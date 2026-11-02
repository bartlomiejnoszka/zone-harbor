<?php

declare(strict_types=1);

namespace OvhDns\Provider;

/** Omitted fields remain omitted during partial updates; null TTL explicitly selects the provider default. */
final readonly class Mutation
{
    public function __construct(public array $fields) {}

    public static function fromLegacy(array $fields): self
    {
        $map = ['fieldType' => 'type', 'subDomain' => 'owner', 'target' => 'rdata', 'ttl' => 'ttl'];
        $neutral = [];
        foreach ($fields as $key => $value) $neutral[$map[$key]] = $key === 'ttl' && $value === 0 ? null : $value;
        return new self($neutral);
    }

    public function legacy(): array
    {
        $map = ['type' => 'fieldType', 'owner' => 'subDomain', 'rdata' => 'target', 'ttl' => 'ttl'];
        $fields = [];
        foreach ($this->fields as $key => $value) $fields[$map[$key]] = $key === 'ttl' && $value === null ? 0 : $value;
        return $fields;
    }
}
