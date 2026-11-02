<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Ovh\Api;
use OvhDns\Dns\DnsService;
use OvhDns\Dns\OvhDnsClient;
use OvhDns\Mcp\DnsTools;
use OvhDns\Operation\WriteExecutor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class McpTest extends TestCase
{
    private array $history = [];
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/ovh-mcp-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rmdir($this->directory);
    }

    private function tools(array $responses = [], ?string $directory = null): DnsTools
    {
        // Native export is followed by a separate normalized record snapshot.
        $queue = [];
        foreach ($responses as $response) {
            $queue[] = $response;
            if ($response instanceof Response && is_string(json_decode((string) $response->getBody(), true))) $queue[] = $this->response([]);
        }
        $responses = $queue;
        $handler = new MockHandler([new Response(200, [], (string) time()), ...$responses]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $dns = new DnsService(static fn () => new OvhDnsClient(new Api('test-application-key', 'test-secret', 'ovh-eu', 'test-consumer', new Client(['handler' => $stack]))));
        return new DnsTools($dns, new WriteExecutor($dns, $directory ?? $this->directory));
    }

    private function response(mixed $value, int $code = 200): Response
    {
        return new Response($code, ['Content-Type' => 'application/json'], json_encode($value));
    }

    private function methods(): array
    {
        return array_map(static fn ($entry) => $entry['request']->getMethod(), array_slice($this->history, 1));
    }

    public function testPreviewNormalizesInputWithoutOpeningApiOrCreatingBackup(): void
    {
        $result = $this->tools()->add('EXAMPLE.COM.', 'txt', '@', '"demo"');
        self::assertFalse($result->isError);
        self::assertSame('preview', $result->structuredContent['status']);
        self::assertSame('', $result->structuredContent['fields']['subDomain']);
        self::assertSame('TXT', $result->structuredContent['fields']['fieldType']);
        self::assertSame([], $this->history);
        self::assertSame([], glob($this->directory.'/*'));
    }

    public function testInvalidFieldsNeverReachApi(): void
    {
        foreach ([['example.com', 'TXT', '@', '', 0], ['example.com', 'A', 'bad..name', '1.2.3.4', 0], ['example.com', 'A', '@', '1.2.3.4', -1]] as $args) {
            self::assertTrue($this->tools()->add(...$args)->isError);
        }
        self::assertSame([], $this->history);
    }

    public function testReadUsesStructuredResult(): void
    {
        $result = $this->tools([$this->response(['example.com'])])->zones();
        self::assertSame(['example.com'], $result->structuredContent['zones']);
        self::assertSame(['GET'], $this->methods());
    }

    public function testWriteBacksUpBeforeMutationAndRefreshesOnce(): void
    {
        $result = $this->tools([$this->response('zone-backup'), $this->response(['id' => 42]), $this->response(null)])
            ->add('example.com', 'TXT', '@', '"demo"', dryRun: false);
        self::assertFalse($result->isError);
        self::assertSame('publication_accepted', $result->structuredContent['status']);
        self::assertSame('42', $result->structuredContent['id']);
        self::assertSame(['GET', 'GET', 'POST', 'POST'], $this->methods());
        $backup = $result->structuredContent['backup'];
        self::assertSame('zone-backup', file_get_contents($backup.'/before.zone'));
        self::assertSame(0600, fileperms($backup.'/before.zone') & 0777);
        self::assertFileExists($backup.'/result.json');
    }

    public function testDeferredWriteDoesNotRefresh(): void
    {
        $result = $this->tools([$this->response('zone'), $this->response(null)])
            ->update('example.com', '42', ttl: 0, refresh: false, dryRun: false);
        self::assertSame('saved_pending_publication', $result->structuredContent['status']);
        self::assertSame(['GET', 'GET', 'PUT'], $this->methods());
    }

    public function testFailedPublicationRetainsRecordIdWithoutRepeatingWrite(): void
    {
        $result = $this->tools([$this->response('zone'), $this->response(['id' => 42]), $this->response(['secret' => 'test-secret'], 503)])
            ->add('example.com', 'A', '@', '203.0.113.1', dryRun: false);
        self::assertTrue($result->isError);
        self::assertSame('saved_publication_unconfirmed', $result->structuredContent['status']);
        self::assertSame('42', $result->structuredContent['id']);
        self::assertSame(['GET', 'GET', 'POST', 'POST'], $this->methods());
        self::assertStringNotContainsString('test-secret', json_encode($result));
    }

    public function testUnknownWriteIsNotRetriedAndLeavesIntentBackup(): void
    {
        $result = $this->tools([$this->response('zone'), $this->response(['secret' => 'test-secret'], 503)])
            ->add('example.com', 'A', '@', '203.0.113.1', dryRun: false);
        self::assertSame('outcome_unknown', $result->structuredContent['status']);
        self::assertSame(['GET', 'GET', 'POST'], $this->methods());
        self::assertCount(1, glob($this->directory.'/*/intent.json'));
        self::assertStringNotContainsString('test-secret', json_encode($result));
    }

    public function testBackupFailurePreventsWrite(): void
    {
        $result = $this->tools([$this->response([], 403)])->add('example.com', 'TXT', '@', '"demo"', dryRun: false);
        self::assertTrue($result->isError);
        self::assertSame(['GET'], $this->methods());
    }

    public function testUnwritableBackupPathPreventsAnyApiCall(): void
    {
        file_put_contents($this->directory.'/file', 'not a directory');
        $result = $this->tools(directory: $this->directory.'/file')->refresh('example.com', false);
        self::assertTrue($result->isError);
        self::assertSame([], $this->history);
    }

    public function testLockPreventsConcurrentMutation(): void
    {
        $lock = (new LockFactory(new FlockStore($this->directory)))->createLock('ovh:default:example.com');
        self::assertTrue($lock->acquire());
        try {
            self::assertTrue($this->tools()->refresh('example.com', false)->isError);
            self::assertSame([], $this->history);
        } finally { $lock->release(); }
    }

    public function testDeleteRequiresIntentAndPreviewReadsExactRecord(): void
    {
        $tools = $this->tools([$this->response(['id' => 42, 'target' => '203.0.113.1'])]);
        self::assertTrue($tools->delete('example.com', '42', dryRun: false)->isError);
        self::assertSame([], $this->history);
        $result = $tools->delete('example.com', '42');
        self::assertSame(42, $result->structuredContent['record']['id']);
        self::assertSame(['GET'], $this->methods());
    }

    public function testConfirmedDeleteAndRefresh(): void
    {
        $result = $this->tools([$this->response(['id' => 42]), $this->response('zone'), $this->response(null), $this->response(null)])
            ->delete('example.com', '42', dryRun: false, confirmDelete: true);
        self::assertFalse($result->isError);
        self::assertSame(['GET', 'GET', 'GET', 'DELETE', 'POST'], $this->methods());
    }
}
