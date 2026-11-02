<?php

declare(strict_types=1);

namespace OvhDns\Health;

final class Report implements \JsonSerializable
{
    /** @var list<Finding> */
    public array $checks = [];
    public function __construct(public readonly string $target, public readonly \DateTimeImmutable $observedAt) {}
    public function add(Finding $finding): void { $this->checks[] = $finding; }
    public function status(): Status
    {
        foreach ([Status::FAIL, Status::UNKNOWN, Status::WARN] as $status) foreach ($this->checks as $check) if ($check->status === $status) return $status;
        return Status::PASS;
    }
    public function exitCode(): int { return match ($this->status()) { Status::FAIL => 1, Status::UNKNOWN => 3, default => 0 }; }
    public function jsonSerialize(): array
    {
        return ['target' => $this->target, 'status' => $this->status()->value, 'observedAt' => $this->observedAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'), 'checks' => $this->checks];
    }
}
