<?php

declare(strict_types=1);

namespace OvhDns\Provider;

/** RDATA is preserved verbatim. Null TTL means the provider default, never an assumed number of seconds. */
final readonly class Record implements \JsonSerializable
{
    public function __construct(
        public string $reference, public string $zone, public string $type,
        public string $owner, public string $rdata, public ?int $ttl,
        public array $metadata = [],
    ) {}

    public function jsonSerialize(): array
    {
        return ['reference' => $this->reference, 'zone' => $this->zone, 'type' => $this->type,
            'owner' => $this->owner, 'rdata' => $this->rdata, 'ttl' => $this->ttl, 'metadata' => $this->metadata];
    }

    /** Compatibility serializer for the original OVH CLI/MCP wire fields. */
    public function legacy(): array
    {
        if (isset($this->metadata['ovh'])) return $this->metadata['ovh'];
        return array_replace($this->metadata, ['id' => $this->metadata['id'] ?? $this->reference,
            'zone' => $this->zone, 'fieldType' => $this->type, 'subDomain' => $this->owner,
            'target' => $this->rdata, 'ttl' => $this->ttl ?? 0]);
    }
}
