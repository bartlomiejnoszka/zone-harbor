<?php

declare(strict_types=1);

namespace OvhDns\Provider;

use Closure;
use OvhDns\Dns\DnsInput;
use OvhDns\Dns\OvhDnsClient;
use Symfony\Component\Console\Exception\InvalidArgumentException;

final class OvhProvider implements DnsProvider
{
    private ?OvhDnsClient $client = null;
    /** @param Closure(): OvhDnsClient $factory */
    public function __construct(private readonly Closure $factory, private readonly string $account = 'default') {}
    private function client(): OvhDnsClient { return $this->client ??= ($this->factory)(); }
    public function key(): string { return 'ovh:'.$this->account; }
    public function capabilities(): Capabilities { return new Capabilities(DnsInput::types(), true, true); }
    public function validateReference(string $reference): string
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $reference)) throw new InvalidArgumentException('OVH record ID must be a positive integer.');
        return $reference;
    }
    public function zones(): array { return array_map(static fn (string $name): Zone => new Zone($name, $name), $this->client()->listZones()); }
    public function records(string $zone, ?string $type = null, ?string $owner = null): array
    {
        return array_map(fn (array $record): Record => $this->map($zone, $record), $this->client()->listRecords($zone, $type, $owner));
    }
    public function record(string $zone, string $reference): Record { return $this->map($zone, $this->client()->getRecord($zone, $this->validateReference($reference))); }
    public function create(string $zone, Mutation $mutation): Record { return $this->map($zone, $this->client()->createRecord($zone, $mutation->legacy()) + $mutation->legacy()); }
    public function update(string $zone, string $reference, Mutation $mutation): void { $this->client()->updateRecord($zone, $this->validateReference($reference), $mutation->legacy()); }
    public function delete(string $zone, string $reference): void { $this->client()->deleteRecord($zone, $this->validateReference($reference)); }
    public function publish(string $zone): void { $this->client()->refreshZone($zone); }
    public function export(string $zone): string { return $this->client()->exportZone($zone); }
    private function map(string $zone, array $data): Record
    {
        return new Record((string) $data['id'], $zone, $data['fieldType'] ?? '', $data['subDomain'] ?? '', $data['target'] ?? '',
            ($data['ttl'] ?? 0) === 0 ? null : $data['ttl'], ['ovh' => $data]);
    }
}
