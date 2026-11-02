<?php

declare(strict_types=1);

namespace OvhDns\Tests\Support;

use OvhDns\Tui\Application;
use OvhDns\Tui\Gateway;
use OvhDns\Tui\State;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/** Drives the real widget tree with keyboard input, without OVH or credentials. */
final class TuiSnapshotSession
{
    public readonly State $state;
    public readonly Application $app;
    public readonly VirtualTerminal $terminal;
    public readonly SnapshotGateway $gateway;
    private readonly Tui $tui;
    private ScreenBuffer $screen;

    /** @param (\Closure(State): void)|null $configure */
    public function __construct(int $columns = 120, int $rows = 34, ?\Closure $configure = null)
    {
        $this->state = new State();
        $this->state->zone = 'example.test';
        $this->state->zones = ['example.test', 'other.example.test', 'xn--prba-5qa.example'];
        $this->state->pane = 'records';
        $this->state->updatedAt = '12:34:56';
        $this->state->message = 'Loaded 7 records. Selection previews the value; Enter expands it.';
        $this->state->records = [
            ['id' => 101, 'fieldType' => 'A', 'subDomain' => '', 'ttl' => 3600, 'target' => '203.0.113.10'],
            ['id' => 102, 'fieldType' => 'AAAA', 'subDomain' => '', 'ttl' => 3600, 'target' => '2001:db8::10'],
            ['id' => 103, 'fieldType' => 'MX', 'subDomain' => '', 'ttl' => 0, 'target' => '10 mail.example.test.'],
            ['id' => 104, 'fieldType' => 'TXT', 'subDomain' => '', 'ttl' => 3600, 'target' => '"v=spf1 include:_spf.google.com ~all"'],
            ['id' => 105, 'fieldType' => 'TXT', 'subDomain' => 'google._domainkey', 'ttl' => 3600, 'target' => '"v=DKIM1; k=rsa; p='.str_repeat('PUBLIC_TEST_KEY', 30).'"'],
            ['id' => 106, 'fieldType' => 'CNAME', 'subDomain' => 'www', 'ttl' => 300, 'target' => 'example.test.'],
            ['id' => 107, 'fieldType' => 'SRV', 'subDomain' => '_submission._tcp', 'ttl' => 600, 'target' => '0 1 587 mail.example.test.'],
        ];
        $configure?->__invoke($this->state);
        $this->terminal = new VirtualTerminal($columns, $rows);
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->tui = new Tui(terminal: $this->terminal);
        $this->gateway = new SnapshotGateway($this->state);
        $this->app = new Application($this->tui, $this->gateway, $this->state);
        $this->app->tick();
        $this->tui->start();
        $this->flush();
    }

    public function keys(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->terminal->simulateInput($key);
            $this->flush();
        }
    }

    public function resize(int $columns, int $rows): void
    {
        $this->terminal->simulateResize($columns, $rows);
        $this->screen = new ScreenBuffer($columns, $rows);
        $this->tui->requestRender(true);
        $this->flush();
    }

    private function flush(): void
    {
        $this->app->tick();
        $this->tui->processRender();
        $this->screen->write($this->terminal->consumeOutput());
    }

    /** @return array{screen: string, styles: string} */
    public function capture(): array
    {
        $this->flush();
        return ['screen' => $this->screen->getScreen()."\n",
            // Escapes stay visible in JSON diffs; never store executable ANSI bytes.
            'styles' => json_encode(explode("\n", $this->screen->getStyledScreen()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n"];
    }

    public function close(): void { $this->tui->stop(); }
}

final class SnapshotGateway implements Gateway
{
    private ?array $request = null;
    public int $writeAttempts = 0;

    public function __construct(private readonly State $state) {}

    public function start(array $request): void
    {
        if (($request['action'] ?? '') !== 'preview') {
            ++$this->writeAttempts;
            throw new \LogicException('Screen snapshots only support previews. No network is available.');
        }
        $this->request = $request;
    }

    public function poll(): ?array
    {
        if ($this->request === null) return null;
        $request = $this->request;
        $this->request = null;
        unset($request['action']);
        $result = $request + ['status' => 'preview', 'fields' => [], 'refresh' => true];
        if (isset($request['id'])) {
            foreach ($this->state->records as $record) {
                if ((string) $record['id'] === $request['id']) $result['record'] = $record;
            }
        }
        return $result;
    }

    public function isRunning(): bool { return $this->request !== null; }
    public function isWriting(): bool { return false; }
    public function cancelRead(): void { $this->request = null; }
}
