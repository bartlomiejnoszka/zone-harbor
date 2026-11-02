<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class Application
{
    public readonly State $state;
    private readonly Dashboard $dashboard;
    private readonly Keybindings $keys;
    /** @var list<\Symfony\Component\Tui\Widget\InputWidget|\Symfony\Component\Tui\Widget\EditorWidget|\Symfony\Component\Tui\Widget\SelectListWidget> */
    private array $inputs = [];
    private int $inputIndex = 0;
    private string $renderedScreen = '';
    private string $pendingAction = '';
    private string $size = '';
    private bool $small = false;
    private ?RecordForm $form = null;
    private ?TextWidget $footer = null;
    private ?TextWidget $palette = null;

    public function __construct(private readonly Tui $tui, private readonly Gateway $gateway, ?State $state = null)
    {
        $this->state = $state ?? new State();
        $this->dashboard = new Dashboard($this->state);
        $this->keys = new Keybindings([
            'up' => ['up'], 'down' => ['down'], 'left' => ['left'], 'right' => ['right'],
            'page_up' => ['page_up'], 'page_down' => ['page_down'], 'tab' => ['tab'], 'previous' => ['shift+tab'],
            'enter' => ['enter'], 'escape' => ['escape'], 'interrupt' => ['ctrl+c'], 'reload' => ['r', 'f5'], 'palette' => ['ctrl+p'],
        ]);
        $tui->addStyleSheet(new StyleSheet([':root' => new Style(gap: 0)]));
        $tui->addListener(function (InputEvent $event): void { if ($this->input($event->getData())) $event->stopPropagation(); });
        $tui->onTick(function (): ?bool { $this->tick(); return $this->gateway->isRunning() ? true : null; });
    }

    public function run(?string $zone = null, bool $healthOnly = false): void
    {
        $this->state->zone = $zone;
        $this->refresh();
        $this->state->healthOnly = $healthOnly;
        if ($healthOnly) $this->action('h');
        else $this->request(['action' => 'zones']);
        try { $this->tui->run(); }
        finally { if (!$this->gateway->isWriting()) $this->gateway->cancelRead(); }
    }

    public function quit(): void
    {
        if ($this->gateway->isWriting()) {
            $this->state->message = 'A write is running. Wait for its outcome before exiting.';
            $this->refresh();
            return;
        }
        $this->gateway->cancelRead();
        $this->tui->stop();
    }

    public function tick(): void
    {
        $terminal = $this->tui->getTerminal();
        $size = $terminal->getColumns().':'.$terminal->getRows();
        if ($this->size !== $size) {
            $this->size = $size;
            $this->small = $terminal->getColumns() < 80 || $terminal->getRows() < 24;
            $this->refresh(true);
        }
        if (($result = $this->gateway->poll()) === null) return;
        $s = $this->state;
        $s->busy = false;
        if ($this->pendingAction === 'write') {
            $s->result = $result;
            $s->plan = null; // A confirmation is single-use, including unknown outcomes.
            $s->message = 'Operation finished. Review the result before another change.';
            $s->open('result');
        } elseif (isset($result['error'])) {
            $s->message = $result['error'];
        } elseif ($this->pendingAction === 'health') {
            $s->healthReport = $result['report'];
            $s->healthIndex = 0;
            $s->open('health');
            $s->message = 'Health audit completed. Enter shows evidence; r reruns. No DNS writes.';
        } elseif ($this->pendingAction === 'zones') {
            $s->zones = $result['zones'];
            $s->message = $s->zones === [] ? 'No accessible DNS zones.' : 'Choose a zone with Enter.';
            if ($s->zone !== null && !in_array($s->zone, $s->zones, true)) {
                $s->message = 'Requested zone is not accessible. Choose another zone.';
                $s->zone = null;
            }
            if ($s->zone !== null || $s->zones !== []) {
                $s->zoneFilter = '';
                $s->zoneIndex = $s->zone === null ? 0 : (int) array_search($s->zone, $s->zones, true);
                $this->loadZone();
            }
        } elseif ($this->pendingAction === 'records') {
            $id = $s->selectedRecord()['id'] ?? null;
            $s->records = $result['records'];
            $s->recordIndex = 0;
            foreach ($s->visibleRecords() as $i => $record) if ($record['id'] === $id) $s->recordIndex = $i;
            $s->updatedAt = date('H:i:s');
            $s->message = 'Loaded '.count($s->records).' records. Selection previews the value; Enter expands it.';
        } elseif ($this->pendingAction === 'preview') {
            $s->plan = $result;
            $s->message = 'Preview only. No DNS changes have been made.';
            $s->open('preview');
        }
        $this->refresh();
    }

    /** True consumes the event; false lets the focused native field handle it. */
    public function input(string $data): bool
    {
        $s = $this->state;
        $this->keys->setKittyProtocolActive($this->tui->getTerminal()->isKittyProtocolActive());
        if ($this->keys->matches($data, 'interrupt')) { $this->quit(); return true; }
        if ($this->keys->matches($data, 'escape')) {
            if ($this->gateway->isWriting()) $s->message = 'A running write cannot be cancelled.';
            else {
                $this->gateway->cancelRead();
                if ($s->screen === 'search') {
                    if ($s->searchScope === 'zones') { $s->zoneFilter = $s->searchOriginal; $s->zoneIndex = 0; }
                    else { $s->filter = $s->searchOriginal; $s->recordIndex = 0; }
                }
                $s->busy = false;
                $s->plan = null;
                $s->open($s->screen === 'health_details' || $s->healthOnly ? 'health' : 'browse');
                $s->message = $s->screen === 'health' ? 'Read cancelled or health view restored.' : 'Back to records. Reload with r.';
            }
            $this->refresh();
            return true;
        }
        if ($this->small || $s->busy) {
            if ($data === 'q') $this->quit();
            return true;
        }
        if ($s->screen === 'palette') {
            if ($this->keys->matches($data, 'up')) $s->move(-1);
            elseif ($this->keys->matches($data, 'down')) $s->move(1);
            elseif ($this->keys->matches($data, 'enter')) $this->runCommand();
            else return false;
            $this->refresh();
            return true;
        }
        if (in_array($s->screen, ['form', 'search', 'health_target'], true)) {
            if ($s->screen === 'form' && ($this->keys->matches($data, 'tab') || $this->keys->matches($data, 'previous'))) {
                $this->nextField($this->keys->matches($data, 'previous') ? -1 : 1);
                return true;
            }
            return false;
        }
        if ($data === 'q') { $this->quit(); return true; }
        if (in_array($s->screen, ['health', 'health_details'], true)) {
            if ($this->keys->matches($data, 'up')) $s->move(-1);
            elseif ($this->keys->matches($data, 'down')) $s->move(1);
            elseif ($this->keys->matches($data, 'page_up')) $s->move(-10);
            elseif ($this->keys->matches($data, 'page_down')) $s->move(10);
            elseif ($this->keys->matches($data, 'enter')) $s->open('health_details');
            elseif ($this->keys->matches($data, 'reload')) $this->action('h');
            elseif ($data === '?') $s->open('help');
            elseif ($data === 'h') { $s->healthTarget = $s->zone ?? ''; $s->open('health_target'); }
            $this->refresh(); return true;
        }
        if ($this->keys->matches($data, 'up')) $s->move(-1);
        elseif ($this->keys->matches($data, 'down')) $s->move(1);
        elseif ($this->keys->matches($data, 'page_up')) $s->move(-10);
        elseif ($this->keys->matches($data, 'page_down')) $s->move(10);
        elseif ($s->screen === 'preview') {
            if ($data === 'y' && $s->plan !== null) $this->request(['action' => 'write'] + $s->plan);
        } elseif ($this->keys->matches($data, 'palette')) {
            $s->commandFilter = '';
            $s->actionIndex = 0;
            $s->open('palette');
        } elseif (in_array($data, ['1', '2', '3', '4', '?'], true)) {
            $s->open(match ($data) { '3' => 'actions', '4', '?' => 'help', default => 'browse' });
            if ($data === '1' || $data === '2') $s->pane = $data === '1' ? 'zones' : 'records';
            if ($data === '3') $s->actionIndex = 0;
        } elseif ($s->screen === 'browse' && $this->keys->matches($data, 'tab')) {
            $s->pane = $s->pane === 'zones' ? 'records' : 'zones';
        } elseif ($s->screen === 'browse' && $s->pane === 'records' && ($this->keys->matches($data, 'left') || $this->keys->matches($data, 'right'))) {
            $i = (int) array_search($s->typeFilter, State::GROUPS, true);
            $s->typeFilter = State::GROUPS[($i + ($this->keys->matches($data, 'right') ? 1 : count(State::GROUPS) - 1)) % count(State::GROUPS)];
            $s->recordIndex = 0;
        } elseif ($this->keys->matches($data, 'enter')) {
            if ($s->screen === 'actions') $this->runCommand();
            elseif ($s->screen === 'browse') {
                if ($s->pane === 'zones') $this->loadZone();
                elseif ($s->selectedRecord() !== null) $s->open('details');
            }
        } elseif ($s->screen === 'browse') {
            $this->action($this->keys->matches($data, 'reload') ? 'r' : $data);
        }
        $this->refresh();
        return true;
    }

    private function nextField(int $direction = 1): void
    {
        $n = count($this->inputs);
        $this->inputIndex = ($this->inputIndex + ($direction > 0 ? 1 : $n - 1)) % max(1, $n);
        $this->tui->setFocus($this->inputs[$this->inputIndex] ?? null);
    }

    private function runCommand(): void
    {
        if (($command = $this->state->commands()[$this->state->actionIndex] ?? null) === null) return;
        $this->state->open('browse');
        $this->action($command['key']);
    }

    private function action(string $key): void
    {
        $s = $this->state;
        if ($s->healthOnly && !in_array($key, ['h', '?'], true)) return;
        if ($key === 'h') {
            if ($s->zone === null) { $s->healthTarget = ''; $s->open('health_target'); }
            else { $s->open('health'); $this->request(['action' => 'health', 'zone' => $s->zone]); }
        } elseif ($key === 'r') {
            $s->open('browse');
            $this->request($s->zone === null ? ['action' => 'zones'] : ['action' => 'records', 'zone' => $s->zone]);
        } elseif ($key === '/') {
            $s->searchScope = $s->pane;
            $s->searchOriginal = $s->pane === 'zones' ? $s->zoneFilter : $s->filter;
            $s->searchDraft = $s->searchOriginal;
            $s->open('search');
        } elseif ($key === 'a' || $key === 'e') {
            if ($s->beginForm($key === 'e')) $s->message = 'Complete the fields, then preview the change.';
            else $s->message = 'Choose a zone and a record first.';
        } elseif ($key === 'd' && $s->zone !== null && ($r = $s->selectedRecord()) !== null) {
            $this->request(['action' => 'preview', 'operation' => 'record:delete', 'zone' => $s->zone, 'id' => (string) $r['id']]);
        } elseif ($key === 'p' && $s->zone !== null) {
            $this->request(['action' => 'preview', 'operation' => 'zone:refresh', 'zone' => $s->zone]);
        } elseif ($key === 'v') {
            if ($this->tui->getTerminal()->getRows() < 30) { $s->compactPreview = !$s->compactPreview; $s->showPreview = true; }
            else $s->showPreview = !$s->showPreview;
        } elseif ($key === '?') $s->open('help');
    }

    private function loadZone(): void
    {
        $s = $this->state;
        if (!isset($s->visibleZones()[$s->zoneIndex])) return;
        $next = $s->visibleZones()[$s->zoneIndex];
        if ($next !== $s->zone) {
            $s->records = [];
            $s->filter = '';
            $s->typeFilter = 'All';
            $s->recordIndex = 0;
            $s->updatedAt = null;
        }
        $s->zone = $next;
        $s->pane = 'records';
        $s->open('browse');
        $this->request(['action' => 'records', 'zone' => $s->zone]);
    }

    private function submit(): void
    {
        $s = $this->state;
        if ($s->busy) return;
        if ($s->screen === 'health_target') {
            try { $s->zone = \OvhDns\Dns\DnsInput::zone($s->healthTarget); $this->action('h'); }
            catch (\InvalidArgumentException) { $s->message = 'Enter an ASCII/punycode domain name.'; }
        } elseif ($s->screen === 'search') {
            $s->open('browse');
            $s->message = 'Filter applied.';
        } elseif ($s->screen === 'form') {
            try {
                $this->request(['action' => 'preview', 'operation' => $s->operation, 'zone' => $s->zone, 'id' => $s->id, 'fields' => $s->validatedFields()]);
            } catch (\InvalidArgumentException) { $s->message = 'Review the highlighted fields. No DNS request was made.'; }
        }
        $this->refresh();
    }

    private function request(array $request): void
    {
        if ($this->gateway->isRunning()) return;
        try {
            $this->gateway->start($request);
            $this->pendingAction = $request['action'];
            $this->state->busy = true;
            $this->state->message = $request['action'] === 'write' ? 'Saving and publishing. Do not interrupt the process.' : ($request['action'] === 'health' ? 'Inspecting public DNS and TLS (read-only)...' : 'Loading from OVH...');
        } catch (\Throwable) { $this->state->message = 'Cannot start the request worker. Check PHP and process permissions.'; }
        $this->refresh();
    }

    private function refresh(bool $force = false): void
    {
        $s = $this->state;
        if ($this->renderedScreen !== $s->screen || $force) {
            $oldIndex = $this->renderedScreen === $s->screen ? $this->inputIndex : 0;
            $this->tui->setFocus(null);
            $this->tui->clear();
            $this->inputs = [];
            $this->form = null;
            $this->footer = $this->palette = null;
            $dialog = in_array($s->screen, ['form', 'palette'], true) && !$this->small;
            $this->dashboard->expandVertically(!$dialog);
            $this->tui->add($this->dashboard);
            if (!$this->small) {
                if ($s->screen === 'form') {
                    $this->form = new RecordForm($s, $this->submit(...), $this->refresh(...), $this->nextField(...), $this->tui->getTerminal()->getRows());
                    $this->centerDialog($this->form);
                    $this->inputs = $this->form->fields;
                } elseif ($s->screen === 'health_target') {
                    $input = (new InputWidget())->setPrompt('Domain: ')->setValue($s->healthTarget);
                    $input->onChange(function () use ($input): void { $this->state->healthTarget = $input->getValue(); });
                    $input->onSubmit($this->submit(...));
                    $this->inputs[] = $input;
                    $this->tui->add($input);
                } elseif ($s->screen === 'search' || $s->screen === 'palette') {
                    $palette = $s->screen === 'palette';
                    $input = (new InputWidget())->setPrompt($palette ? 'Action: ' : 'Search: ')->setValue($palette ? $s->commandFilter : $s->searchDraft);
                    $input->onChange(function () use ($input, $palette): void {
                        $s = $this->state;
                        if ($palette) { $s->commandFilter = $input->getValue(); $s->actionIndex = 0; }
                        else {
                            $s->searchDraft = $input->getValue();
                            if ($s->searchScope === 'zones') { $s->zoneFilter = $s->searchDraft; $s->zoneIndex = 0; }
                            else { $s->filter = $s->searchDraft; $s->recordIndex = 0; }
                        }
                        $this->refresh();
                    });
                    $input->onSubmit(function () use ($palette): void { $palette ? $this->runCommand() : $this->submit(); $this->refresh(); });
                    $this->inputs[] = $input;
                    if ($palette) {
                        $box = (new ContainerWidget())->setStyle(new Style(border: Border::all(1, color: Theme::ACCENT), padding: Padding::from([0, 1]), maxColumns: 96, gap: 0));
                        $box->add((new TextWidget('Command palette'))->setStyle(Theme::accent()));
                        $box->add($input);
                        $this->palette = new TextWidget();
                        $box->add($this->palette);
                        $box->add((new TextWidget('[↑/↓] Choose · [Enter] Open · [Esc] Cancel'))->setStyle(Theme::muted()));
                        $this->centerDialog($box);
                    } else $this->tui->add($input);
                }
                if ($dialog) { $this->footer = new TextWidget(); $this->tui->add($this->footer); }
                $this->inputIndex = min($oldIndex, max(0, count($this->inputs) - 1));
                $this->tui->setFocus($this->inputs[$this->inputIndex] ?? null);
            }
            $this->renderedScreen = $s->screen;
        }
        $this->form?->showErrors();
        if ($this->palette !== null) {
            $lines = [];
            foreach ($s->commands() as $i => $command) {
                $line = ' ['.$command['key'].'] '.Dashboard::plain($command['label']);
                $lines[] = $s->actionIndex === $i ? Theme::selected()->apply($line) : $line;
            }
            $this->palette->setText(implode("\n", $lines ?: ['No matching actions.']));
        }
        $status = $this->dashboard->status($this->tui->getTerminal()->getColumns());
        $this->footer?->setText(implode("\n", $this->tui->getTerminal()->getRows() < 30 ? array_slice($status, 0, 1) : $status));
        $this->dashboard->invalidate();
        $this->tui->requestRender();
    }

    private function centerDialog(ContainerWidget $dialog): void
    {
        $offset = max(0, intdiv($this->tui->getTerminal()->getColumns() - 96, 2));
        $wrapper = (new ContainerWidget())->setStyle(new Style(padding: Padding::from([0, 0, 0, $offset]), gap: 0));
        $wrapper->add($dialog);
        $this->tui->add($wrapper);
    }
}
