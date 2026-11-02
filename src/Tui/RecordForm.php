<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use OvhDns\Dns\DnsInput;
use Symfony\Component\Tui\Event\SelectionChangeEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\EditorWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/** Native focusable fields; editing this view never invokes DNS. */
final class RecordForm extends ContainerWidget
{
    /** @var list<InputWidget|EditorWidget|SelectListWidget> */
    public array $fields = [];
    /** @var array<string, TextWidget> */
    private array $errors = [];

    public function __construct(private readonly State $state, callable $submit, callable $changed, callable $next, int $rows = 34)
    {
        $this->setStyle(new Style(border: Border::all(1, color: Theme::ACCENT), padding: Padding::from([0, 1]), maxColumns: 96, gap: 0));
        $editing = $state->operation === 'record:update';
        $this->add((new TextWidget($editing ? 'Edit record #'.$state->id : 'Add record'))->setStyle(Theme::accent()));
        if ($rows >= 30) $this->add((new TextWidget('Zone: '.Dashboard::plain($state->zone ?? '').' · @ = apex · TTL 0 = zone default'))->setStyle(Theme::muted()));
        if (!$editing) {
            $this->add(new TextWidget('Type · ↑/↓ to choose, Tab to continue'));
            $types = array_values(array_unique(['TXT', 'A', 'AAAA', 'CNAME', 'MX', 'SRV', ...DnsInput::types()]));
            $type = new SelectListWidget(array_map(fn (string $t): array => ['value' => $t, 'label' => $t], $types), maxVisible: 3);
            $type->setId('record-field-fieldType');
            $type->setSelectedIndex((int) array_search($state->form['fieldType'], $types, true));
            $type->onSelectionChange(function (SelectionChangeEvent $event) use ($changed): void {
                $this->state->form['fieldType'] = $event->getValue();
                unset($this->state->formErrors['fieldType']);
                $changed();
            });
            $type->onSelect(static function () use ($next): void { $next(); });
            $this->add($type);
            $this->fields[] = $type;
            $this->error('fieldType');
        } else $this->add(new TextWidget('Type: '.Dashboard::plain($state->form['fieldType']).' (fixed)'));
        foreach (['subDomain' => 'Name', 'ttl' => 'TTL (seconds)'] as $key => $label) {
            $input = (new InputWidget())->setPrompt($label.': ')->setValue($state->form[$key]);
            $input->setId('record-field-'.$key);
            $input->onChange(function () use ($key, $input, $changed): void {
                $this->state->form[$key] = $input->getValue();
                unset($this->state->formErrors[$key]);
                $changed();
            });
            $input->onSubmit(static function () use ($submit): void { $submit(); });
            $this->add($input);
            $this->fields[] = $input;
            $this->error($key);
        }
        $this->add(new TextWidget('Target · TXT uses zone-file quotes; Shift+Enter inserts a newline'));
        $editorRows = $rows >= 30 ? 7 : 5;
        $target = (new EditorWidget())->setMinVisibleLines($editorRows)->setMaxVisibleLines($editorRows)->setText($state->form['target']);
        $target->setId('record-field-target');
        $target->onChange(function () use ($target, $changed): void {
            $this->state->form['target'] = $target->getText();
            unset($this->state->formErrors['target']);
            $changed();
        });
        $target->onSubmit(static function () use ($submit): void { $submit(); });
        $this->add($target);
        $this->fields[] = $target;
        $this->error('target');
        $this->add((new TextWidget('[Enter] Preview change   [Tab / Shift+Tab] Fields   [Esc] Cancel'))->setStyle(Theme::accent()));
    }

    private function error(string $key): void
    {
        $widget = (new TextWidget('', truncate: true))->setStyle(Theme::danger());
        $widget->setId('record-error-'.$key);
        $this->errors[$key] = $widget;
        $this->add($widget);
    }

    public function showErrors(): void
    {
        foreach ($this->errors as $key => $widget) $widget->setText(isset($this->state->formErrors[$key]) ? '! '.Dashboard::plain($this->state->formErrors[$key]) : '');
    }
}
