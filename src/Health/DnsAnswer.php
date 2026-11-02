<?php

declare(strict_types=1);

namespace OvhDns\Health;

final readonly class DnsAnswer implements \JsonSerializable
{
    /** @param list<array{name: string, type: string, value: string, ttl: int}> $records */
    public function __construct(public string $status, public array $records = [], public bool $authoritative = false, public string $source = '') {}
    public function values(string $name, string $type): array
    {
        $values = [];
        foreach ($this->records as $rr) if (strcasecmp(rtrim($rr['name'], '.'), rtrim($name, '.')) === 0 && $rr['type'] === $type) {
            $value = $rr['value'];
            if (in_array($type, ['NS', 'MX', 'CNAME', 'SOA'], true)) $value = strtolower(rtrim($value, '.'));
            $values[] = $value;
        }
        $values = array_values(array_unique($values)); sort($values, SORT_STRING);
        return $values;
    }
    public function known(): bool { return in_array($this->status, ['NOERROR', 'NXDOMAIN'], true); }
    public function jsonSerialize(): array { return get_object_vars($this); }
}
