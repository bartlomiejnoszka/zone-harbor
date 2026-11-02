<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use OvhDns\Dns\DnsInput;

/**
 * Terminal state only. DNS operations live in Worker/DnsService.
 *
 * @phpstan-type DnsRecord array{id: int|string, fieldType: string, subDomain: string, ttl: int, target: string, zone?: string}
 * @phpstan-type Screen 'browse'|'details'|'search'|'form'|'actions'|'palette'|'help'|'preview'|'result'|'health'|'health_details'|'health_target'
 */
final class State
{
    public bool $healthOnly = false;
    public array $healthReport = [];
    public int $healthIndex = 0;
    public string $healthTarget = '';
    public string $label = 'ZoneHarbor';
    /** @var list<string> */
    public array $zones = [];
    public int $zoneIndex = 0;
    public ?string $zone = null;
    /** @var list<DnsRecord> */
    public array $records = [];
    public int $recordIndex = 0;
    public string $filter = '';
    public string $searchDraft = '';
    /** @var 'zones'|'records' */
    public string $searchScope = 'records';
    public string $searchOriginal = '';
    public string $zoneFilter = '';
    public string $typeFilter = 'All';
    public const GROUPS = ['All', 'A/AAAA', 'MX', 'TXT', 'Other'];
    public bool $showPreview = true;
    public bool $compactPreview = false;
    public ?string $updatedAt = null;
    public string $commandFilter = '';
    /** @var array<string, string> */
    public array $formErrors = [];
    /** @var 'zones'|'records' */
    public string $pane = 'zones';
    /** @var Screen */
    public string $screen = 'browse';
    public int $actionIndex = 0;
    public int $scroll = 0;
    public int $scrollLimit = 0;
    public string $message = 'Loading zones...';
    /** @var array{fieldType: string, subDomain: string, ttl: string, target: string} */
    public array $form = ['fieldType' => 'TXT', 'subDomain' => '@', 'ttl' => '3600', 'target' => ''];
    public string $operation = '';
    public ?string $id = null;
    public ?array $plan = null;
    public ?array $result = null;
    public bool $busy = false;

    /** @return list<DnsRecord> */
    public function visibleRecords(): array
    {
        return array_values(array_filter($this->records, fn (array $record): bool => ($this->filter === '' || str_contains(
            strtolower(implode(' ', [$record['fieldType'], $record['subDomain'] ?: '@', $record['target']])), strtolower($this->filter)))
            && match ($this->typeFilter) {
                'A/AAAA' => in_array($record['fieldType'], ['A', 'AAAA'], true),
                'MX' => $record['fieldType'] === 'MX',
                'TXT' => $record['fieldType'] === 'TXT',
                'Other' => !in_array($record['fieldType'], ['A', 'AAAA', 'MX', 'TXT'], true),
                default => true,
            }));
    }

    /** @return list<string> */
    public function visibleZones(): array
    {
        return array_values(array_filter($this->zones, fn (string $zone): bool => str_contains(strtolower($zone), strtolower($this->zoneFilter))));
    }

    /** @return list<array{key: string, label: string}> */
    public function commands(): array
    {
        return array_values(array_filter([
            ['key' => 'h', 'label' => 'Check domain health (read-only)'],
            ['key' => 'r', 'label' => 'Reload records'], ['key' => 'a', 'label' => 'Add record'],
            ['key' => 'e', 'label' => 'Edit selected record'], ['key' => 'd', 'label' => 'Delete selected record'],
            ['key' => 'p', 'label' => 'Publish ALL pending zone changes'], ['key' => 'v', 'label' => 'Toggle record preview'],
            ['key' => '?', 'label' => 'Keyboard help'],
        ], fn (array $command): bool => (!$this->healthOnly || in_array($command['key'], ['h', '?'], true)) && ($this->screen !== 'palette' || str_contains(strtolower($command['label']), strtolower($this->commandFilter)))));
    }

    /** @return DnsRecord|null */
    public function selectedRecord(): ?array { return $this->visibleRecords()[$this->recordIndex] ?? null; }

    /** @param Screen $screen */
    public function open(string $screen): void
    {
        $this->screen = $screen;
        $this->scroll = $this->scrollLimit = 0;
    }

    public function move(int $delta): void
    {
        if ($this->screen === 'health') {
            $this->healthIndex = max(0, min(max(0, count($this->healthReport['checks'] ?? []) - 1), $this->healthIndex + $delta));
        } elseif (in_array($this->screen, ['actions', 'palette'], true)) {
            $this->actionIndex = max(0, min(max(0, count($this->commands()) - 1), $this->actionIndex + $delta));
        } elseif (in_array($this->screen, ['details', 'preview', 'result', 'help', 'health_details'], true)) {
            $this->scroll = max(0, min($this->scrollLimit, $this->scroll + $delta));
        } elseif ($this->pane === 'zones') {
            $this->zoneIndex = max(0, min(max(0, count($this->visibleZones()) - 1), $this->zoneIndex + $delta));
        } else {
            $this->recordIndex = max(0, min(max(0, count($this->visibleRecords()) - 1), $this->recordIndex + $delta));
        }
    }

    public function beginForm(bool $editing): bool
    {
        $record = $editing ? $this->selectedRecord() : null;
        if ($this->zone === null || ($editing && $record === null)) return false;
        $this->operation = $editing ? 'record:update' : 'record:add';
        $this->id = $editing ? (string) $record['id'] : null;
        $this->plan = null;
        $this->formErrors = [];
        $this->form = ['fieldType' => $record['fieldType'] ?? 'TXT', 'subDomain' => ($record['subDomain'] ?? '') ?: '@',
            'ttl' => (string) ($record['ttl'] ?? 3600), 'target' => $record['target'] ?? ''];
        $this->open('form');
        return true;
    }

    public function validatedFields(): array
    {
        $this->formErrors = [];
        $type = strtoupper(trim($this->form['fieldType']));
        try { DnsInput::type($type); }
        catch (\InvalidArgumentException) { $this->formErrors['fieldType'] = 'Unsupported record type. Use a DNS type such as A, AAAA, CNAME, MX or TXT.'; }
        try { $name = DnsInput::relativeName(trim($this->form['subDomain'])); }
        catch (\InvalidArgumentException) { $this->formErrors['subDomain'] = 'Use a relative DNS name, @ or *. Use ASCII/punycode and no trailing dot.'; }
        $ttl = trim($this->form['ttl']);
        if (!preg_match('/^[0-9]+$/D', $ttl) || (float) $ttl > 2147483647) $this->formErrors['ttl'] = 'TTL must be a whole number from 0 to 2147483647.';
        if (trim($this->form['target']) === '') $this->formErrors['target'] = 'Target cannot be empty.';
        if ($this->formErrors !== []) throw new \InvalidArgumentException(reset($this->formErrors));
        $fields = ['subDomain' => $name, 'ttl' => (int) $ttl, 'target' => $this->form['target']];
        if ($this->operation === 'record:add') $fields['fieldType'] = $type;
        return DnsInput::fields($fields, $this->operation === 'record:add');
    }
}
