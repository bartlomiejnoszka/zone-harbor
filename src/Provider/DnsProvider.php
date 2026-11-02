<?php

declare(strict_types=1);

namespace OvhDns\Provider;

/** Implementations must sanitize errors, preserve RDATA, throw sanitized ProviderException errors and never retry an uncertain mutation. */
interface DnsProvider
{
    public function key(): string;
    public function capabilities(): Capabilities;
    public function validateReference(string $reference): string;
    /** @return list<Zone> */
    public function zones(): array;
    /** @return list<Record> */
    public function records(string $zone, ?string $type = null, ?string $owner = null): array;
    public function record(string $zone, string $reference): Record;
    public function create(string $zone, Mutation $mutation): Record;
    public function update(string $zone, string $reference, Mutation $mutation): void;
    public function delete(string $zone, string $reference): void;
    public function publish(string $zone): void;
    public function export(string $zone): string;
}
