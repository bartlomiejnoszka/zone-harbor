<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use Symfony\Component\Process\Process;

final class ProcessGateway implements Gateway
{
    private ?Process $process = null;
    private bool $writing = false;

    /** @param list<string>|null $command Internal injection point for subprocess tests. */
    public function __construct(private readonly ?array $command = null) {}

    public function start(array $request): void
    {
        if ($this->isRunning()) throw new \LogicException('A request is already running.');
        $process = new Process($this->command ?? [PHP_BINARY, dirname(__DIR__, 2).'/bin/dns-worker']);
        // A process timeout would terminate a write with an ambiguous remote outcome.
        $process->setTimeout(null);
        $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->start();
        $this->process = $process;
        $this->writing = ($request['action'] ?? '') === 'write';
    }

    public function poll(): ?array
    {
        if ($this->process === null || $this->process->isRunning()) return null;
        try {
            $result = json_decode($this->process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($result) || !is_string($result['status'] ?? null) || !$this->process->isSuccessful()) throw new \RuntimeException();
        } catch (\Throwable) {
            // Never expose worker stderr, which could contain environment or SDK internals.
            $result = ['status' => $this->writing ? 'outcome_unknown' : 'failed', 'error' => $this->writing
                ? Worker::outcomeMessage('outcome_unknown') : 'The request worker failed. Check configuration and reload.'];
        }
        $this->process = null;
        $this->writing = false;
        return $result;
    }

    public function isRunning(): bool { return $this->process !== null; }
    public function isWriting(): bool { return $this->process !== null && $this->writing; }

    public function cancelRead(): void
    {
        if ($this->isWriting()) throw new \LogicException('A running write cannot be cancelled.');
        $this->process?->stop(0);
        $this->process = null;
    }
}
