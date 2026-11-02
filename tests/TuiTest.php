<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ovh\Api;
use OvhDns\Console\ApplicationFactory;
use OvhDns\Dns\DnsService;
use OvhDns\Dns\OvhDnsClient;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Tui\Application;
use OvhDns\Tui\Dashboard;
use OvhDns\Tui\Gateway;
use OvhDns\Tui\ProcessGateway;
use OvhDns\Tui\State;
use OvhDns\Tui\Worker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TuiTest extends TestCase
{
    private array $history = [];
    private string $directory;
    private const RECORD = ['id' => 42, 'fieldType' => 'TXT', 'subDomain' => '_demo', 'ttl' => 3600, 'target' => '"old"'];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ovh-tui-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }

    private function worker(array $responses = []): Worker
    {
        // Native export is followed by a separate normalized record snapshot.
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = $response;
            if ($response instanceof Response && is_string(json_decode((string) $response->getBody(), true))) $queue[] = $this->response([]);
        }
        $responses = $queue;
        $mock = new MockHandler([new Response(200, [], (string) time()), ...$responses]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $dns = new DnsService(static fn () => new OvhDnsClient(new Api('test-key', 'test-secret', 'ovh-eu', 'test-consumer', new Client(['handler' => $stack]))));
        return new Worker($dns, new WriteExecutor($dns, $this->directory));
    }

    private function response(mixed $value, int $status = 200): Response
    {
        return new Response($status, [], json_encode($value));
    }

    private function request(string $action, string $operation = 'record:update'): array
    {
        return ['action' => $action, 'operation' => $operation, 'zone' => 'example.com', 'id' => '42',
            'fields' => $operation === 'record:delete' ? [] : ['target' => '"new"'], 'record' => self::RECORD];
    }

    public function testPreviewReadsTheExactRecordWithoutWriting(): void
    {
        $result = $this->worker([$this->response(self::RECORD)])->handle($this->request('preview'));
        self::assertSame('preview', $result['status']);
        self::assertSame(self::RECORD, $result['record']);
        self::assertSame(['GET', 'GET'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        self::assertSame([], glob($this->directory.'/*'));
    }

    public function testChangedRecordRejectsConfirmedUpdateAndDeleteBeforeBackupOrMutation(): void
    {
        foreach (['record:update', 'record:delete'] as $operation) {
            $result = $this->worker([$this->response(array_replace(self::RECORD, ['target' => '"changed elsewhere"']))])->handle($this->request('write', $operation));
            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('changed since preview', $result['error']);
        }
        foreach ($this->history as $entry) self::assertSame('GET', $entry['request']->getMethod());
        self::assertSame([], glob($this->directory.'/*/before.zone'));
    }

    public function testConfirmedWriteBacksUpAndPublishesExactlyOnce(): void
    {
        $result = $this->worker([$this->response(self::RECORD), $this->response('backup'), $this->response(null), $this->response(null)])
            ->handle($this->request('write'));
        self::assertSame('publication_accepted', $result['status']);
        self::assertSame('backup', file_get_contents($result['backup'].'/before.zone'));
        self::assertFileExists($result['backup'].'/intent.json');
        self::assertFileExists($result['backup'].'/result.json');
        self::assertSame(['GET', 'GET', 'GET', 'GET', 'PUT', 'POST'], array_map(fn ($h) => $h['request']->getMethod(), $this->history));
        self::assertSame(0600, fileperms($result['backup'].'/before.zone') & 0777);
    }

    public function testMissingSnapshotCannotWrite(): void
    {
        $request = $this->request('write');
        unset($request['record']);
        self::assertSame('invalid_input', $this->worker()->handle($request)['status']);
        self::assertSame([], $this->history);
    }

    public function testMcpAndTuiUseTheSameZoneLock(): void
    {
        $lock = (new LockFactory(new FlockStore($this->directory)))->createLock('ovh:default:example.com');
        self::assertTrue($lock->acquire());
        try {
            $result = $this->worker()->handle($this->request('write'));
            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('Another write', $result['error']);
            self::assertSame([], $this->history);
        } finally { $lock->release(); }
    }

    public function testPartialPublicationHasEnglishRecoveryAndDoesNotRepeatWrite(): void
    {
        $result = $this->worker([$this->response(self::RECORD), $this->response('backup'), $this->response(null), $this->response([], 500)])
            ->handle($this->request('write'));
        self::assertSame('saved_publication_unconfirmed', $result['status']);
        self::assertStringContainsString('do not repeat', $result['error']);
        self::assertCount(1, array_filter($this->history, fn ($h) => $h['request']->getMethod() === 'PUT'));
    }

    public function testAmbiguousWriteIsNotRetriedAndDoesNotExposeSdkErrors(): void
    {
        $result = $this->worker([$this->response(self::RECORD), $this->response('backup'), $this->response(['message' => 'test-secret'], 500)])
            ->handle($this->request('write'));
        self::assertSame('outcome_unknown', $result['status']);
        self::assertStringNotContainsString('test-secret', json_encode($result));
        self::assertCount(5, $this->history);
        self::assertFileExists($result['backup'].'/result.json');
    }

    public function testFilterNavigationAndEnglishValidation(): void
    {
        $s = new State();
        $s->zone = 'example.com';
        $s->records = [self::RECORD, array_replace(self::RECORD, ['id' => 43, 'fieldType' => 'MX', 'subDomain' => '', 'target' => '10 mail.example.com.'])];
        foreach (['mx', 'MAIL.', '@'] as $filter) {
            $s->filter = $filter;
            self::assertSame(43, $s->selectedRecord()['id']);
        }
        $s->pane = 'records';
        $s->move(100);
        self::assertSame(0, $s->recordIndex);
        self::assertTrue($s->beginForm(true));
        self::assertArrayNotHasKey('fieldType', $s->validatedFields());
        $s->form['ttl'] = '-1';
        try { $s->validatedFields(); self::fail('Invalid TTL accepted.'); }
        catch (\InvalidArgumentException $error) { self::assertStringStartsWith('TTL must', $error->getMessage()); }
        $s->form['ttl'] = '0';
        $s->form['target'] = '';
        $this->expectExceptionMessage('Target cannot be empty.');
        $s->validatedFields();
    }

    public function testViewSanitizesControlsAndScrollsTheFullLongValue(): void
    {
        $s = new State();
        $long = str_repeat('abcdefghijklmnopqrstuvwxyz', 200).'END_OF_DKIM';
        $s->records = [array_replace(self::RECORD, ['target' => $long])];
        $s->open('details');
        $view = new Dashboard($s);
        $context = new RenderContext(100, 28);
        self::assertStringNotContainsString('END_OF_DKIM', implode('\n', $view->render($context)));
        self::assertGreaterThan(0, $s->scrollLimit);
        $s->move(1000);
        self::assertStringContainsString('END_OF_DKIM', implode('\n', $view->render($context)));
        self::assertSame('value', Dashboard::plain("\e]52;c;malicious\x07\e[31mvalue\e[0m"));
        self::assertStringNotContainsString("\e", Dashboard::plain("\e[2Jhello\x9b"));
        self::assertStringContainsString('Resize terminal', implode('\n', $view->render(new RenderContext(70, 20))));
        foreach ($view->render($context) as $line) self::assertLessThanOrEqual(100, AnsiUtils::visibleWidth($line));
    }

    private function ui(): array
    {
        $s = new State();
        $s->zones = ['example.com', 'other.example'];
        $s->zone = 'example.com';
        $s->pane = 'records';
        $s->records = [self::RECORD];
        $terminal = new VirtualTerminal(120, 34);
        $tui = new Tui(terminal: $terminal);
        $gateway = new FakeTuiGateway();
        $ui = new Application($tui, $gateway, $s);
        $ui->tick();
        return [$s, $terminal, $tui, $gateway, $ui];
    }

    public function testConfirmationIsSingleUseAndBusyWriteCannotBeQuitOrCancelled(): void
    {
        [$s, $terminal, $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('d');
        self::assertSame('preview', $gateway->requests[0]['action']);
        $gateway->reply = $this->request('preview', 'record:delete') + ['status' => 'preview'];
        $ui->tick();
        self::assertSame('preview', $s->screen);
        $tui->handleInput('y');
        self::assertSame('write', $gateway->requests[1]['action']);
        $tui->handleInput('y');
        $tui->handleInput('q');
        $tui->handleInput("\x1b");
        self::assertCount(2, $gateway->requests);
        self::assertTrue($gateway->isWriting());
        self::assertSame(0, $gateway->cancellations);
        $gateway->reply = ['status' => 'outcome_unknown'];
        $ui->tick();
        self::assertSame('result', $s->screen);
        self::assertNull($s->plan);
        $tui->handleInput('y');
        self::assertCount(2, $gateway->requests);
        $tui->stop();
    }

    public function testFormSearchMenusAndEscapeDoNotWrite(): void
    {
        [$s, $terminal, $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('e');
        self::assertSame('form', $s->screen);
        $tui->handleInput("\t");
        $tui->handleInput("\x15"); // clear TTL, native input widget
        $tui->handleInput('99');
        $tui->handleInput("\r");
        self::assertSame(99, $gateway->requests[0]['fields']['ttl']);
        $tui->handleInput("\x1b");
        self::assertSame('browse', $s->screen);
        self::assertFalse($s->busy);
        $tui->handleInput('/');
        $tui->handleInput('_demo');
        $terminal->simulateResize(80, 24);
        $ui->tick();
        $terminal->simulateResize(120, 34);
        $ui->tick();
        $tui->handleInput("\r");
        self::assertSame('_demo', $s->filter);
        $tui->handleInput('3');
        self::assertSame('actions', $s->screen);
        $tui->handleInput('4');
        self::assertSame('help', $s->screen);
        self::assertCount(1, $gateway->requests);
        self::assertSame('preview', $gateway->requests[0]['action']);
        $tui->stop();
    }

    public function testPublishActionRequiresPreviewAndConfirmation(): void
    {
        [$s, , $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('p');
        self::assertSame(['action' => 'preview', 'operation' => 'zone:refresh', 'zone' => 'example.com'], $gateway->requests[0]);
        $gateway->reply = ['status' => 'preview', 'operation' => 'zone:refresh', 'zone' => 'example.com', 'id' => null, 'fields' => [], 'refresh' => true];
        $ui->tick();
        self::assertSame('preview', $s->screen);
        $tui->handleInput("\r");
        self::assertCount(1, $gateway->requests); // Enter does not confirm.
        $tui->handleInput('y');
        self::assertSame('write', $gateway->requests[1]['action']);
        self::assertSame('zone:refresh', $gateway->requests[1]['operation']);
        $gateway->reply = ['status' => 'publication_accepted'];
        $ui->tick();
        self::assertSame('result', $s->screen);
        $tui->stop();
    }

    public function testSubprocessProtocolKeepsPayloadOffArgumentsAndHidesStderr(): void
    {
        $g = new ProcessGateway([PHP_BINARY, '-r', 'fwrite(STDERR,"private-secret"); echo "bad JSON";']);
        $g->start(['action' => 'write', 'target' => 'sensitive-value']);
        self::assertTrue($g->isWriting());
        try { $g->cancelRead(); self::fail('Write was cancelled.'); } catch (\LogicException) {}
        $deadline = microtime(true) + 5;
        do { $result = $g->poll(); if ($result === null) usleep(10000); } while ($result === null && microtime(true) < $deadline);
        self::assertSame('outcome_unknown', $result['status']);
        self::assertStringNotContainsString('private-secret', json_encode($result));
        self::assertFalse($g->isRunning());
    }

    public function testNonInteractiveCommandFailsWithoutTerminalEscapes(): void
    {
        $app = ApplicationFactory::create();
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        self::assertSame(2, $tester->run(['command' => 'dns:tui'], ['interactive' => false]));
        self::assertStringContainsString('requires an interactive terminal', $tester->getDisplay());
        self::assertStringNotContainsString("\e", $tester->getDisplay());
    }

    public function testResponsivePanelsAndPreviewFitAllSupportedSizes(): void
    {
        $s = new State();
        $s->zone = 'example.com';
        $s->zones = ['example.com'];
        $s->pane = 'records';
        $s->records = [self::RECORD];
        $view = new Dashboard($s);
        foreach ([[80, 24], [100, 28], [120, 34], [160, 45]] as [$w, $h]) {
            $lines = $view->render(new RenderContext($w, $h));
            self::assertCount($h, $lines);
            foreach ($lines as $line) self::assertLessThanOrEqual($w, AnsiUtils::visibleWidth($line));
            $plain = AnsiUtils::stripAnsiCodes(implode("\n", $lines));
            self::assertStringContainsString('Records · 1/1', $plain);
            if ($w >= 110) self::assertStringContainsString('Zones · 1', $plain);
            else self::assertStringNotContainsString('Find zone', $plain);
            if ($h >= 30) self::assertStringContainsString('Selected record', $plain);
        }
        $s->pane = 'zones';
        self::assertStringContainsString('Find zone', implode("\n", $view->render(new RenderContext(80, 24))));
        $s->pane = 'records';
        $s->compactPreview = true;
        self::assertStringContainsString('Selected record', implode("\n", $view->render(new RenderContext(80, 24))));
    }

    public function testSelectionPreviewsValuesAndTypeFiltersCombineWithSearch(): void
    {
        [$s, , $tui, , $ui] = $this->ui();
        $s->records[] = array_replace(self::RECORD, ['id' => 43, 'fieldType' => 'MX', 'target' => '10 mail.example.com.']);
        $tui->handleInput("\e[B");
        self::assertSame('browse', $s->screen);
        self::assertSame(43, $s->selectedRecord()['id']);
        self::assertStringContainsString('10 mail.example.com.', implode("\n", (new Dashboard($s))->render(new RenderContext(120, 34))));
        $tui->handleInput("\e[C");
        self::assertSame('A/AAAA', $s->typeFilter);
        self::assertSame([], $s->visibleRecords());
        $tui->handleInput("\e[C");
        self::assertSame('MX', $s->typeFilter);
        self::assertSame(43, $s->selectedRecord()['id']);
        $tui->handleInput('/');
        $tui->handleInput('missing');
        self::assertSame([], $s->visibleRecords());
        $tui->handleInput("\e");
        self::assertSame('', $s->filter);
        self::assertSame(43, $s->selectedRecord()['id']);
        $tui->stop();
    }

    public function testZoneSearchLoadsTheFilteredZoneRatherThanTheOriginalIndex(): void
    {
        [$s, , $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('1');
        $tui->handleInput('/');
        $tui->handleInput('other');
        self::assertSame(['other.example'], $s->visibleZones());
        $tui->handleInput("\r");
        $tui->handleInput("\r");
        self::assertSame('other.example', $s->zone);
        self::assertSame(['action' => 'records', 'zone' => 'other.example'], $gateway->requests[0]);
        self::assertSame([], $s->records);
        $tui->stop();
    }

    public function testCommandPaletteSearchCannotExecuteWriteWithoutPreview(): void
    {
        [$s, , $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput("\x10");
        self::assertSame('palette', $s->screen);
        $tui->handleInput('delete');
        self::assertCount(1, $s->commands());
        self::assertCount(0, $gateway->requests);
        $tui->handleInput("\r");
        self::assertSame('preview', $gateway->requests[0]['action']);
        self::assertSame('record:delete', $gateway->requests[0]['operation']);
        $tui->handleInput("\e");
        self::assertCount(1, $gateway->requests);
        $tui->stop();
    }

    public function testNativeFormShowsErrorsAndPreservesTargetAndFocusAcrossResize(): void
    {
        [$s, $terminal, $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('a');
        $tui->handleInput("\e[B"); // Choose A in the native type selector.
        self::assertSame('A', $s->form['fieldType']);
        $tui->handleInput("\r"); // Choosing a type advances; it does not preview.
        self::assertSame('record-field-subDomain', $tui->getFocus()->getId());
        $tui->handleInput("\t");
        $tui->handleInput("\x15");
        $tui->handleInput('-1');
        $tui->handleInput("\r");
        self::assertArrayHasKey('ttl', $s->formErrors);
        self::assertArrayHasKey('target', $s->formErrors);
        self::assertStringContainsString('TTL must', $tui->getById('record-error-ttl')->getText());
        self::assertCount(0, $gateway->requests);
        $tui->handleInput("\x15");
        $tui->handleInput('600');
        self::assertArrayNotHasKey('ttl', $s->formErrors);
        $tui->handleInput("\t");
        $long = '"'.str_repeat('public-key-data', 60).'"';
        $tui->handleInput($long);
        self::assertSame($long, $s->form['target']);
        $terminal->simulateResize(80, 24);
        $ui->tick();
        self::assertSame('record-field-target', $tui->getFocus()->getId());
        self::assertSame($long, $tui->getById('record-field-target')->getText());
        $tui->handleInput("\r");
        self::assertSame('preview', $gateway->requests[0]['action']);
        self::assertSame($long, $gateway->requests[0]['fields']['target']);
        self::assertSame(600, $gateway->requests[0]['fields']['ttl']);
        $tui->stop();
    }

    public function testPreviewDisplaysOnlyChangedFieldsAndSanitizesExternalValues(): void
    {
        $s = new State();
        $s->open('preview');
        $s->plan = ['operation' => 'record:update', 'zone' => 'example.com', 'id' => '42', 'record' => self::RECORD,
            'fields' => ['subDomain' => '_demo', 'ttl' => 3600, 'target' => "\e]52;c;malicious\x07\"new\""]];
        $rendered = AnsiUtils::stripAnsiCodes(implode("\n", (new Dashboard($s))->render(new RenderContext(120, 34))));
        self::assertStringContainsString('- "old"', $rendered);
        self::assertStringContainsString('+ "new"', $rendered);
        self::assertStringNotContainsString('3600', $rendered);
        self::assertStringNotContainsString('malicious', $rendered);
        self::assertStringContainsString('ALL pending changes', $rendered);
    }

    public function testNativeEditorReplacesAnExistingValueWithoutAppendingIt(): void
    {
        [$s, , $tui, $gateway, $ui] = $this->ui();
        $tui->handleInput('e');
        $tui->handleInput("\t");
        $tui->handleInput("\t");
        $tui->handleInput("\x05"); // End of the existing logical line.
        $tui->handleInput("\x15"); // Remove it before typing the replacement.
        $tui->handleInput('"replacement"');
        $tui->handleInput("\r");
        self::assertSame('preview', $gateway->requests[0]['action']);
        self::assertSame('42', $gateway->requests[0]['id']);
        self::assertSame('"replacement"', $gateway->requests[0]['fields']['target']);
        self::assertSame('_demo', $gateway->requests[0]['fields']['subDomain']);
        $tui->stop();
    }

    public function testHealthViewUsesReadWorkerAndNeverConfirmsWrites(): void
    {
        [$s, , $tui, $gateway, $ui] = $this->ui();
        $s->healthOnly = true;
        $tui->handleInput('h');
        self::assertSame(['action' => 'health', 'zone' => 'example.com'], $gateway->requests[0]);
        self::assertFalse($gateway->isWriting());
        $gateway->reply = ['status' => 'ok', 'report' => ['status' => 'PASS', 'observedAt' => '2026-01-01T00:00:00Z', 'checks' => [['id' => 'dns.delegation', 'status' => 'PASS', 'summary' => 'Matches.', 'evidence' => ['value' => 'example'], 'source' => 'public', 'remediation' => '']]]];
        $ui->tick(); self::assertSame('health', $s->screen);
        $tui->handleInput("\r"); self::assertSame('health_details', $s->screen);
        $tui->handleInput("\e"); self::assertSame('health', $s->screen);
        foreach (['a', 'e', 'd', 'p', 'y'] as $key) $tui->handleInput($key);
        self::assertCount(1, $gateway->requests);
        $tui->handleInput('r'); self::assertSame('health', $gateway->requests[1]['action']);
        $tui->handleInput("\e"); self::assertFalse($s->busy); self::assertSame(1, $gateway->cancellations);
        $tui->stop();
    }

    public function testCompactNativeFormIncludingAllErrorsFitsTheTerminal(): void
    {
        $s = new State();
        $s->zone = 'example.com';
        $s->beginForm(false);
        $s->form['subDomain'] = 'invalid..name';
        $s->form['ttl'] = '-1';
        try { $s->validatedFields(); } catch (\InvalidArgumentException) {}
        $root = new \Symfony\Component\Tui\Widget\ContainerWidget();
        $root->add((new Dashboard($s))->expandVertically(false));
        $form = new \OvhDns\Tui\RecordForm($s, fn () => null, fn () => null, fn () => null, 24);
        $form->showErrors();
        $root->add($form);
        $lines = (new \Symfony\Component\Tui\Render\Renderer())->renderFrame($root, 80, 24);
        self::assertLessThanOrEqual(24, count($lines));
        foreach ($lines as $line) self::assertLessThanOrEqual(80, AnsiUtils::visibleWidth($line));
        self::assertStringContainsString('Target cannot be empty', implode("\n", iterator_to_array($lines)));
    }
}

/** A controllable request boundary, so UI tests can assert that no extra write occurs. */
final class FakeTuiGateway implements Gateway
{
    public array $requests = [];
    public ?array $reply = null;
    public int $cancellations = 0;
    private ?array $active = null;
    public function start(array $request): void { $this->requests[] = $this->active = $request; }
    public function poll(): ?array
    {
        if ($this->reply === null) return null;
        $result = $this->reply;
        $this->reply = $this->active = null;
        return $result;
    }
    public function isRunning(): bool { return $this->active !== null; }
    public function isWriting(): bool { return ($this->active['action'] ?? '') === 'write'; }
    public function cancelRead(): void { if ($this->active !== null) ++$this->cancellations; $this->active = null; }
}
