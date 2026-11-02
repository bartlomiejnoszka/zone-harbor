<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/** Read-only projection of terminal state. External text is sanitized before styling. */
final class Dashboard extends AbstractWidget implements VerticallyExpandableInterface
{
    private bool $expanded = true;
    public function __construct(private readonly State $state) {}
    public function expandVertically(bool $expand): static { $this->expanded = $expand; return $this; }
    public function isVerticallyExpanded(): bool { return $this->expanded; }

    public static function plain(string $value): string
    {
        return preg_replace('/[\x00-\x1f\x7f-\x9f]/u', ' ', mb_scrub(AnsiUtils::stripAnsiCodes($value), 'UTF-8')) ?? '';
    }

    private static function plainMultiline(string $value): string { return implode("\n", array_map(self::plain(...), explode("\n", $value))); }

    private static function fit(string $value, int $width): string
    {
        return AnsiUtils::truncateToWidth($value, max(1, $width), '…', true);
    }

    private static function cell(string $value, int $width): string { return self::fit(self::plain($value), $width); }

    public function render(RenderContext $context): array
    {
        $w = $context->getColumns();
        $h = $context->getRows();
        $s = $this->state;
        // Embedded forms reserve their own rows below this compact header.
        if ($this->expanded && ($w < 80 || $h + (in_array($s->screen, ['search', 'health_target'], true) ? 1 : 0) < 24)) return [Theme::accent()->apply('ZoneHarbor'), 'Resize terminal to at least 80 columns × 24 rows.', 'q / Ctrl+C: exit after any running write completes.'];
        $head = self::plain(' '.$s->label.'  ›  '.($s->zone ?? 'Choose a zone'));
        $updated = $s->updatedAt === null ? 'Not loaded' : 'Updated '.$s->updatedAt;
        $head = self::fit($head, max(1, $w - 21)).self::cell($updated, 21);
        $lines = [Theme::accent()->apply($head), (new Style(background: Theme::SURFACE))->apply(self::fit($s->healthOnly ? ' [h] Domain   [r] Rerun   [Enter] Evidence   [?] Help' : ' [1] Zones   [2] Records   [3] Actions   [h] Health   [?] Help', $w))];
        if (!$this->expanded) return $lines;
        $bodyHeight = $h - 5;
        if (in_array($s->screen, ['browse', 'search'], true)) {
            $body = $this->browse($w, $bodyHeight, $h + (in_array($s->screen, ['search', 'health_target'], true) ? 1 : 0));
        } else {
            $width = min($w, 100);
            $body = $this->screenPanel($width, $bodyHeight);
            if ($width < $w) $body = array_map(fn (string $line): string => str_repeat(' ', intdiv($w - $width, 2)).$line, $body);
        }
        array_push($lines, ...$body);
        return [...$lines, ...$this->status($w), Theme::accent()->apply(self::fit($this->shortcuts($w), $w))];
    }

    public function status(int $width): array
    {
        $s = $this->state;
        $message = ($s->busy ? 'Working · ' : 'Ready · ').self::plain($s->message);
        $rows = array_slice(TextWrapper::wrapTextWithAnsi($message, max(1, $width - 2)), 0, 2);
        return array_map(fn (string $line): string => Theme::muted()->apply(self::fit(' '.$line, $width)), array_pad($rows, 2, ''));
    }

    private function browse(int $w, int $h, int $terminalHeight): array
    {
        $s = $this->state;
        if ($w < 110 && $s->pane === 'zones') return $this->zones($w, $h);
        $sidebar = $w >= 110 ? 25 : 0;
        $rightWidth = $w - ($sidebar > 0 ? $sidebar + 1 : 0);
        $preview = $s->showPreview && ($terminalHeight >= 30 || $s->compactPreview);
        $tableHeight = $preview ? max(8, (int) floor($h * 0.58)) : $h;
        $right = $this->records($rightWidth, $tableHeight);
        if ($preview) array_push($right, ...$this->recordPreview($rightWidth, $h - $tableHeight));
        if ($sidebar === 0) return $right;
        $left = $this->zones($sidebar, $h);
        return array_map(fn (int $i): string => $left[$i].' '.$right[$i], range(0, $h - 1));
    }

    private function zones(int $width, int $height): array
    {
        $s = $this->state;
        $zones = $s->visibleZones();
        $rows = [Theme::muted()->apply(self::cell($s->zoneFilter === '' ? '/ Find zone' : '/ '.$s->zoneFilter, $width - 4))];
        $count = $height - 3;
        $start = max(0, $s->zoneIndex - $count + 2);
        for ($i = 0; $i < $count - 1; ++$i) {
            $idx = $start + $i;
            $text = isset($zones[$idx]) ? ($zones[$idx] === $s->zone ? '• ' : '  ').$zones[$idx] : ($i === 0 && $zones === [] ? 'No matching zones' : '');
            $line = self::cell($text, $width - 4);
            $rows[] = $s->pane === 'zones' && $idx === $s->zoneIndex && isset($zones[$idx]) ? Theme::selected()->apply($line) : $line;
        }
        return $this->box('Zones · '.count($zones), $rows, $width, $height, $s->pane === 'zones');
    }

    private function records(int $width, int $height): array
    {
        $s = $this->state;
        $inner = $width - 4;
        $groups = [];
        foreach (State::GROUPS as $group) $groups[] = $group === $s->typeFilter ? Theme::accent()->apply('['.$group.']') : Theme::muted()->apply(' '.$group.' ');
        $rows = [self::fit(implode(' ', $groups).'  [←/→]', $inner)];
        $nameWidth = min(23, max(15, (int) ($inner * 0.25)));
        $valueWidth = $inner - $nameWidth - 17;
        $rows[] = Theme::muted()->apply(self::cell('TYPE', 7).self::cell('NAME', $nameWidth).self::cell('TTL', 10).self::cell('VALUE', $valueWidth));
        $records = $s->visibleRecords();
        $count = max(1, $height - 4);
        $start = max(0, $s->recordIndex - $count + 1);
        for ($row = 0; $row < $count; ++$row) {
            $idx = $start + $row;
            if (!isset($records[$idx])) {
                $rows[] = $row === 0 ? Theme::muted()->apply('No matching records. [/] Adjust filter or [a] add a record.') : '';
                continue;
            }
            $r = $records[$idx];
            $type = self::cell($r['fieldType'], 7);
            $text = self::cell($r['subDomain'] ?: '@', $nameWidth).self::cell(self::ttl((int) $r['ttl']), 10).self::cell($r['target'], $valueWidth);
            $rows[] = $s->pane === 'records' && $idx === $s->recordIndex ? Theme::selected()->apply($type.$text)
                : (new Style(color: match ($r['fieldType']) { 'A', 'AAAA' => Theme::ACCENT, 'MX' => Theme::SUCCESS, 'TXT' => '#c4b5fd', default => Theme::MUTED }))->apply($type).$text;
        }
        $title = 'Records · '.count($records).'/'.count($s->records).($s->filter === '' ? '' : ' · / '.$s->filter);
        return $this->box($title, $rows, $width, $height, $s->pane === 'records');
    }

    private function recordPreview(int $width, int $height): array
    {
        $r = $this->state->selectedRecord();
        if ($r === null) return $this->box('Selected record', ['Select a record to see its full value.'], $width, $height);
        $content = [Theme::accent()->apply(self::cell($r['fieldType'].' · '.($r['subDomain'] ?: '@').' · ID '.$r['id'].' · TTL '.self::ttl((int) $r['ttl']), $width - 4))];
        $wrapped = TextWrapper::wrapTextWithAnsi(self::plain($r['target']), $width - 4);
        $available = max(1, $height - 4);
        array_push($content, ...array_slice($wrapped, 0, $available));
        $content[] = Theme::muted()->apply(count($wrapped) > $available ? '[Enter] Expand to read the remaining value' : '[Enter] Full record details');
        return $this->box('Selected record', $content, $width, $height);
    }

    public static function ttl(int $seconds): string
    {
        if ($seconds === 0) return 'Default';
        if ($seconds % 86400 === 0) return ($seconds / 86400).'d';
        if ($seconds % 3600 === 0) return ($seconds / 3600).'h';
        if ($seconds % 60 === 0) return ($seconds / 60).'m';
        return $seconds.'s';
    }

    private function screenPanel(int $width, int $height): array
    {
        $s = $this->state;
        $body = $this->body();
        $wrapped = [];
        if ($s->screen === 'health') $body = array_map(fn (string $line): string => self::fit($line, $width - 4), $body);
        foreach ($body as $line) array_push($wrapped, ...TextWrapper::wrapTextWithAnsi($line, $width - 4));
        $count = $height - 2;
        $s->scrollLimit = max(0, count($wrapped) - $count);
        $s->scroll = min($s->scroll, $s->scrollLimit);
        $title = match ($s->screen) { 'details' => 'RECORD DETAILS', 'preview' => 'CONFIRM CHANGE', 'result' => 'OPERATION RESULT', 'help' => 'HELP', 'health' => 'DOMAIN HEALTH', 'health_details' => 'HEALTH EVIDENCE', 'health_target' => 'HEALTH TARGET', default => 'Actions' };
        return $this->box($title.($s->scrollLimit > 0 ? ' · '.($s->scroll + 1).'/'.count($wrapped) : ''), array_slice($wrapped, $s->scroll, $count), $width, $height, true,
            $s->screen === 'preview' ? Theme::WARNING : null);
    }

    /** @param list<string> $rows Trusted styled text; sanitize external values upstream. */
    private function box(string $title, array $rows, int $width, int $height, bool $focused = false, ?string $color = null): array
    {
        $border = new Style(color: $color ?? ($focused ? Theme::ACCENT : Theme::BORDER));
        $caption = ' '.self::plain($title).' ';
        $caption = AnsiUtils::truncateToWidth($caption, $width - 2, '…');
        $top = $border->apply('╭'.$caption.str_repeat('─', max(0, $width - 2 - AnsiUtils::visibleWidth($caption))).'╮');
        $body = [];
        foreach (array_slice(array_pad($rows, max(0, $height - 2), ''), 0, max(0, $height - 2)) as $row) $body[] = $border->apply('│').' '.self::fit($row, $width - 4).' '.$border->apply('│');
        return [$top, ...$body, $border->apply('╰'.str_repeat('─', max(0, $width - 2)).'╯')];
    }

    private function body(): array
    {
        $s = $this->state;
        if ($s->screen === 'health_target') return ['Read-only domain health', '', 'Enter the public domain to inspect. No OVH credentials are required.', 'Mail endpoints and DKIM selectors come from zone-harbor.local.yaml.'];
        if ($s->screen === 'health') {
            $rows = ['Domain: '.self::plain($s->zone ?? ''), 'Status: '.($s->healthReport['status'] ?? 'Waiting'), 'Observed: '.($s->healthReport['observedAt'] ?? ''), ''];
            $checks = $s->healthReport['checks'] ?? [];
            foreach ($checks as $i => $check) {
                $line = ($i === $s->healthIndex ? '> ' : '  ').$check['status'].'  '.$check['id'].'  '.$check['summary'];
                $rows[] = $i === $s->healthIndex ? Theme::selected()->apply(self::plain($line)) : self::plain($line);
            }
            // Keep the selection visible even in a compact terminal.
            $s->scroll = max(0, $s->healthIndex - 3);
            return $rows;
        }
        if ($s->screen === 'health_details') {
            $check = $s->healthReport['checks'][$s->healthIndex] ?? [];
            return ['Read-only evidence', ...explode("\n", self::plainMultiline(json_encode($check, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))];
        }
        if ($s->screen === 'help') return ['Keyboard help', '', '1 Zones · 2 Records · 3 Actions · ? / 4 Help · Ctrl+P Command palette',
            'Tab switches panels. ↑/↓ selects. Enter opens the full record.', '←/→ switches type filters: All, A/AAAA, MX, TXT, Other.',
            '/ searches the focused panel live. Enter keeps the filter; Esc restores it.', 'v toggles the record preview. Narrow terminals show one panel at a time.',
            'a Add · e Edit · d Delete · r / F5 Reload · p Publish', '', 'Forms: Tab / Shift+Tab changes fields. ↑/↓ chooses a record type.',
            'Target supports wrapping, scrolling and Shift+Enter for a newline.', 'Enter previews; it never confirms a write. Esc cancels.',
            'Confirm: review changed fields, then press y to save and publish.', '', 'Publication includes ALL pending zone changes, including changes made elsewhere.',
            'OVH acceptance does not prove public DNS propagation.', 'q / Ctrl+C exits. A running write must finish first.'];
        if (in_array($s->screen, ['actions', 'palette'], true)) {
            $lines = [];
            foreach ($s->commands() as $i => $command) {
                $line = ' ['.$command['key'].'] '.self::plain($command['label']);
                $lines[] = $s->actionIndex === $i ? Theme::selected()->apply($line) : $line;
            }
            return $lines ?: ['No matching actions.'];
        }
        if ($s->screen === 'details') return ['Zone: '.self::plain($s->zone ?? ''), ...$this->recordLines($s->selectedRecord() ?? [])];
        if ($s->screen === 'result') {
            $r = $s->result ?? [];
            $ok = ($r['status'] ?? '') === 'publication_accepted';
            return [(new Style(color: $ok ? Theme::SUCCESS : Theme::WARNING, bold: true))->apply(self::plain($r['status'] ?? '')),
                '', self::plain($r['error'] ?? Worker::outcomeMessage($r['status'] ?? '')), '',
                'Zone: '.self::plain($r['zone'] ?? $s->zone ?? ''), 'Record ID: '.self::plain((string) ($r['id'] ?? '—')),
                'Backup: '.self::plain($r['backup'] ?? 'No backup created.'), self::plain($r['warning'] ?? ''), '', '[Esc] Return to records · [r] Reload before another change'];
        }
        $p = $s->plan ?? [];
        $operation = $p['operation'] ?? '';
        $lines = [Theme::accent()->apply(match ($operation) { 'record:add' => 'ADD RECORD', 'record:update' => 'EDIT RECORD', 'record:delete' => 'DELETE RECORD', default => 'PUBLISH ZONE' }),
            'Zone: '.self::plain($p['zone'] ?? ''), 'Record ID: '.self::plain((string) ($p['id'] ?? '(new record)')), ''];
        if ($operation === 'record:delete') {
            foreach ($this->recordLines($p['record'] ?? []) as $line) $lines[] = Theme::danger()->apply('- '.$line);
        } elseif ($operation !== 'zone:refresh') {
            $before = $p['record'] ?? [];
            $lines[] = Theme::muted()->apply('BEFORE → AFTER · changed fields only');
            foreach (['fieldType' => 'Type', 'subDomain' => 'Name', 'ttl' => 'TTL', 'target' => 'Target'] as $key => $label) {
                if (!array_key_exists($key, $p['fields'] ?? []) || (isset($before[$key]) && (string) $before[$key] === (string) $p['fields'][$key])) continue;
                $lines[] = $label;
                if (array_key_exists($key, $before)) $lines[] = Theme::danger()->apply('- '.self::plain($key === 'subDomain' && $before[$key] === '' ? '@' : (string) $before[$key]));
                $lines[] = (new Style(color: Theme::SUCCESS))->apply('+ '.self::plain($key === 'subDomain' && $p['fields'][$key] === '' ? '@' : (string) $p['fields'][$key]));
            }
        }
        array_push($lines, '', (new Style(color: Theme::WARNING))->apply('Publishes ALL pending changes in this zone, including changes made elsewhere.'),
            'A zone backup is saved before the write.', '', '[y] Confirm & publish     [Esc] Cancel');
        return $lines;
    }

    private function recordLines(array $record): array
    {
        $lines = [];
        foreach (['id' => 'ID', 'fieldType' => 'Type', 'subDomain' => 'Name', 'ttl' => 'TTL (seconds)', 'target' => 'Target'] as $key => $label) {
            if (array_key_exists($key, $record)) $lines[] = $label.': '.self::plain($key === 'subDomain' && $record[$key] === '' ? '@' : (string) $record[$key]);
        }
        return $lines;
    }

    public function shortcuts(int $width = 120): string
    {
        if ($this->state->screen === 'health_target') return ' [Enter] Inspect public domain   [Esc] Back   [q / Ctrl+C] Quit';
        if (in_array($this->state->screen, ['health', 'health_details'], true)) return ' [Enter] Evidence   [r] Rerun   [h] Domain   [↑/↓] Move   [Esc] Back   [q] Quit';
        if ($width < 110 && $this->state->screen === 'browse') return $this->state->pane === 'zones'
            ? ' [Enter] Open  [/] Find  [Tab] Records  [Ctrl+P] Menu  [?] Help'
            : ' [Enter] Open  [/] Find  [a/e/d] Edit  [v] View  [Ctrl+P] Menu  [?] Help';
        return match ($this->state->screen) {
            'preview' => ' [y] Confirm & publish   [Esc] Cancel   [↑/↓] Scroll',
            'details', 'help', 'result' => ' [Esc] Back   [↑/↓] Scroll   [?] Help   [q] Quit',
            'actions', 'palette' => ' [↑/↓] Choose   [Enter] Open   [Esc] Back',
            'search' => ' [Enter] Keep filter   [Esc] Restore filter',
            default => $this->state->pane === 'zones' ? ' [Enter] Open zone   [/] Find zone   [Tab] Records   [Ctrl+P] Actions   [?] Help'
                : ' [Enter] Expand   [/] Find   [a] Add   [e] Edit   [v] Preview   [Ctrl+P] Actions   [?] Help',
        };
    }
}
