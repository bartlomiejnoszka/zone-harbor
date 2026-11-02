<?php

declare(strict_types=1);

namespace OvhDns\Tests\Support;

use OvhDns\Health\{Budget, DnsAnswer, DnsResolver, Endpoint, TlsObservation, TlsProbe};
use OvhDns\Provider\{Capabilities, DnsProvider, Mutation, Record, Zone};
use Psr\Clock\ClockInterface;

final class FakeClock implements ClockInterface
{
    public function __construct(public int $timestamp) {}
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable('@'.$this->timestamp); }
}
final class FakeResolver implements DnsResolver
{
    public array $answers = [];
    public array $queries = [];
    public function set(string $name, string $type, array $values, string $server = '*', string $status = 'NOERROR'): void
    {
        $this->answers[$server.'|'.$name.'|'.$type] = new DnsAnswer($status, array_map(static fn (string $value): array => ['name' => $name, 'type' => $type, 'value' => $value, 'ttl' => 60], $values), true, $server);
    }
    public function query(string $name, string $type, string $server, bool $recursive, float $timeout): DnsAnswer
    {
        $this->queries[] = [$name, $type, $server, $recursive];
        return $this->answers[$server.'|'.$name.'|'.$type] ?? $this->answers['*|'.$name.'|'.$type] ?? new DnsAnswer('NOERROR', authoritative: !$recursive, source: $server);
    }
    public static function healthy(): self
    {
        $dns = new self();
        $dns->set('com', 'NS', ['ns.parent.example']);
        $dns->set('ns.parent.example', 'A', ['1.1.1.2']);
        $dns->set('example.com', 'NS', ['ns1.example.net', 'ns2.example.net']);
        $dns->set('ns1.example.net', 'A', ['1.1.1.3']);
        $dns->set('ns2.example.net', 'A', ['1.1.1.4']);
        $dns->set('example.com', 'SOA', ['ns1.example.net hostmaster.example.com 1 3600 600 86400 60']);
        $dns->set('example.com', 'A', ['93.184.216.34']);
        $dns->set('example.com', 'MX', ['0 ']);
        return $dns;
    }
}
final class FakeTlsProbe implements TlsProbe
{
    public int $calls = 0;
    public function __construct(public string $pem, public bool $verified = true, public bool $fail = false) {}
    public function inspect(Endpoint $endpoint, string $address, Budget $budget): TlsObservation
    {
        ++$this->calls;
        if ($this->fail) throw new \RuntimeException('private diagnostic detail');
        return new TlsObservation($this->pem, $this->verified, $address);
    }
}
final class MemoryProvider implements DnsProvider
{
    public array $data = [];
    public int $writes = 0;
    public function key(): string { return 'memory:default'; }
    public function capabilities(): Capabilities { return new Capabilities(['A', 'TXT'], false, false); }
    public function validateReference(string $reference): string
    {
        if ($reference === '') throw new \InvalidArgumentException('Missing reference.');
        return $reference;
    }
    public function zones(): array { return [new Zone('example.com', 'opaque-zone-ref')]; }
    public function records(string $zone, ?string $type = null, ?string $owner = null): array
    {
        return array_values(array_filter($this->data, static fn (Record $record): bool => ($type === null || $record->type === $type) && ($owner === null || $record->owner === $owner)));
    }
    public function record(string $zone, string $reference): Record { return $this->data[$reference]; }
    public function create(string $zone, Mutation $mutation): Record
    {
        ++$this->writes; $f = $mutation->fields;
        return $this->data['opaque:new'] = new Record('opaque:new', $zone, $f['type'], $f['owner'], $f['rdata'], $f['ttl']);
    }
    public function update(string $zone, string $reference, Mutation $mutation): void
    {
        ++$this->writes; $old = $this->record($zone, $reference); $f = $mutation->fields;
        $this->data[$reference] = new Record($reference, $zone, $old->type, $f['owner'] ?? $old->owner, $f['rdata'] ?? $old->rdata, array_key_exists('ttl', $f) ? $f['ttl'] : $old->ttl, $old->metadata);
    }
    public function delete(string $zone, string $reference): void { ++$this->writes; unset($this->data[$reference]); }
    public function publish(string $zone): void { throw new \LogicException('Publication unsupported.'); }
    public function export(string $zone): string { throw new \LogicException('Native export unsupported.'); }
}
