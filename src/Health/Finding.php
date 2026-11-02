<?php

declare(strict_types=1);

namespace OvhDns\Health;

final readonly class Finding implements \JsonSerializable
{
    public function __construct(public string $id, public Status $status, public string $summary,
        public array $evidence = [], public string $source = 'public', public string $remediation = '') {}
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'status' => $this->status->value, 'summary' => $this->summary,
            'evidence' => $this->evidence, 'source' => $this->source, 'remediation' => $this->remediation];
    }
}
