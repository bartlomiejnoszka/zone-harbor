<?php

declare(strict_types=1);

namespace OvhDns\Tui;

interface Gateway
{
    public function start(array $request): void;
    public function poll(): ?array;
    public function isRunning(): bool;
    public function isWriting(): bool;
    public function cancelRead(): void;
}
