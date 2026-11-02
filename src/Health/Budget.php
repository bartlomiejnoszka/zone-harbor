<?php

declare(strict_types=1);

namespace OvhDns\Health;

final class Budget
{
    private readonly float $deadline;
    public function __construct(float $seconds = 30) { $this->deadline = hrtime(true) / 1e9 + $seconds; }
    public function remaining(): float { return max(0, $this->deadline - hrtime(true) / 1e9); }
    public function timeout(float $maximum): float
    {
        if ($this->remaining() < 0.05) throw new \RuntimeException('Health report time budget exhausted.');
        return min($maximum, $this->remaining());
    }
}
