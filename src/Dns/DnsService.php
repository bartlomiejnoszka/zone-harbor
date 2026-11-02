<?php

declare(strict_types=1);

namespace OvhDns\Dns;

use Closure;
use Throwable;
use OvhDns\Provider\{DnsProvider, OvhProvider, Mutation, Record};

/** Application operations shared by Console, TUI and MCP; no transport formatting here. */
final class DnsService
{
    private readonly DnsProvider $provider;

    /** @param (Closure(): OvhDnsClient)|DnsProvider $clientProvider */
    public function __construct(Closure|DnsProvider $clientProvider)
    {
        $this->provider = $clientProvider instanceof Closure ? new OvhProvider($clientProvider) : $clientProvider;
    }
    public function provider(): DnsProvider { return $this->provider; }
    public function reference(string $id): string { return $this->provider->validateReference($id); }
    public function listZones(): array { return array_map(static fn ($zone): string => $zone->name, $this->provider->zones()); }
    public function exportZone(string $zone): string
    {
        if (!$this->provider->capabilities()->nativeExport) throw new \OvhDns\Provider\UnsupportedCapability('Provider does not support native zone export.');
        return $this->provider->export(DnsInput::zone($zone));
    }
    public function listRecords(string $zone, ?string $type = null, ?string $name = null): array
    {
        return array_map(static fn (Record $record): array => $record->legacy(), $this->provider->records(DnsInput::zone($zone), DnsInput::type($type), DnsInput::relativeName($name)));
    }
    public function getRecord(string $zone, string $id): array { return $this->provider->record(DnsInput::zone($zone), $this->reference($id))->legacy(); }
    public function createRecord(string $zone, array $fields): array
    {
        $fields = $this->fields($fields, true);
        return $this->provider->create(DnsInput::zone($zone), Mutation::fromLegacy($fields))->legacy();
    }
    public function updateRecord(string $zone, string $id, array $fields): void { $this->provider->update(DnsInput::zone($zone), $this->reference($id), Mutation::fromLegacy($this->fields($fields, false))); }
    public function deleteRecord(string $zone, string $id): void { $this->provider->delete(DnsInput::zone($zone), $this->reference($id)); }
    public function refreshZone(string $zone): void
    {
        if (!$this->provider->capabilities()->publication) throw new \OvhDns\Provider\UnsupportedCapability('Provider does not support explicit publication.');
        $this->provider->publish(DnsInput::zone($zone));
    }
    private function fields(array $fields, bool $creating): array
    {
        $fields = DnsInput::fields($fields, $creating);
        if (isset($fields['fieldType']) && !in_array($fields['fieldType'], $this->provider->capabilities()->recordTypes, true)) {
            throw new \Symfony\Component\Console\Exception\InvalidArgumentException('Record type is unsupported by this provider.');
        }
        return $fields;
    }

    public function plan(string $operation, string $zone, ?string $id = null, array $fields = [], bool $refresh = true): array
    {
        $zone = DnsInput::zone($zone);
        if (!in_array($operation, ['record:add', 'record:update', 'record:delete', 'zone:refresh'], true)) {
            throw new \InvalidArgumentException('Unsupported operation.');
        }
        if (in_array($operation, ['record:update', 'record:delete'], true)) {
            $id = $this->reference($id ?? '');
        }
        if (in_array($operation, ['record:add', 'record:update'], true)) {
            $fields = $this->fields($fields, $operation === 'record:add');
        }
        if ($operation === 'zone:refresh' && !$this->provider->capabilities()->publication) throw new \OvhDns\Provider\UnsupportedCapability('Provider does not support explicit publication.');
        $plan = ['operation' => $operation, 'zone' => $zone, 'id' => $id, 'fields' => $fields, 'refresh' => $refresh];
        if ($operation === 'record:delete') {
            $plan['record'] = $this->getRecord($zone, $id);
        }
        return $plan;
    }

    /** A refresh error must never conceal a completed record write. */
    public function publishAfterWrite(string $zone, bool $refresh): array
    {
        if (!$this->provider->capabilities()->publication) return ['status' => 'publication_accepted', 'published' => true];
        if (!$refresh) {
            return ['status' => 'saved_pending_publication', 'published' => false];
        }
        try {
            $this->refreshZone($zone);
            return ['status' => 'publication_accepted', 'published' => true];
        } catch (Throwable) {
            return ['status' => 'saved_publication_unconfirmed', 'published' => false,
                'error' => 'Saved, but publication is unconfirmed. Do not repeat the record write. Inspect the zone and run: php bin/console zone:refresh '.$zone];
        }
    }

    public function executePlan(array $plan): array
    {
        ['operation' => $operation, 'zone' => $zone, 'id' => $id, 'fields' => $fields, 'refresh' => $refresh] = $plan;
        switch ($operation) {
            case 'record:add': $id = (string) $this->createRecord($zone, $fields)['id']; break;
            case 'record:update': $this->updateRecord($zone, $id, $fields); break;
            case 'record:delete': $this->deleteRecord($zone, $id); break;
            case 'zone:refresh':
                $this->refreshZone($zone);
                return ['operation' => $operation, 'zone' => $zone, 'status' => 'publication_accepted', 'published' => true];
            default: throw new \InvalidArgumentException('Unsupported operation.');
        }
        return ['operation' => $operation, 'zone' => $zone, 'id' => $id] + $this->publishAfterWrite($zone, $refresh);
    }
}
