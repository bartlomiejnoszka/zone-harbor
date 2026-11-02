<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Ovh\Api;
use OvhDns\Console\ApplicationFactory;
use OvhDns\Dns\OvhDnsClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\ApplicationTester;
use Symfony\Component\Console\Tester\CommandTester;

final class ConsoleTest extends TestCase
{
    private array $history = [];
    private OvhDnsClient $client;

    private string $directory;
    protected function setUp(): void { $this->directory = sys_get_temp_dir().'/zone-harbor-cli-'.bin2hex(random_bytes(8)); mkdir($this->directory, 0700); }
    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }
    private function mockWrite(array $responses, bool $delete = false): void
    {
        $prefix = [];
        if ($delete) { $record = array_shift($responses); $prefix = [$record, $record]; }
        $this->mock([...$prefix, $this->jsonResponse('zone backup'), $this->jsonResponse([]), ...$responses]);
    }
    private function mutationRequests(): array { return array_values(array_filter($this->requests(), static fn (Request $request): bool => $request->getMethod() !== 'GET')); }

    private function mock(array $responses = []): void
    {
        $this->history = [];
        // The real SDK synchronises its clock before the first signed request.
        $handler = new MockHandler([new Response(200, [], (string) time()), ...$responses]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));
        $this->client = new OvhDnsClient(new Api(
            'test-application-key', 'test-application-secret', 'ovh-eu', 'test-consumer-key',
            new Client(['handler' => $stack]),
        ));
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function record(int $id = 42, array $overrides = []): array
    {
        return array_replace(['id' => $id, 'zone' => 'example.com', 'fieldType' => 'A', 'subDomain' => 'www', 'target' => '203.0.113.10', 'ttl' => 300], $overrides);
    }

    private function runCommand(string $name, array $arguments = [], ?array $answers = null, bool $interactive = false): CommandTester
    {
        $tester = new CommandTester(ApplicationFactory::create($this->client, $this->directory)->find($name));
        if ($answers !== null) {
            $tester->setInputs($answers);
        }
        $tester->execute($arguments, ['interactive' => $interactive, 'capture_stderr_separately' => true, 'verbosity' => OutputInterface::VERBOSITY_DEBUG]);

        return $tester;
    }

    /** @return list<Request> */
    private function requests(): array
    {
        return array_map(static fn (array $entry) => $entry['request'], array_slice($this->history, 1));
    }

    private function assertMethods(array $expected): void
    {
        self::assertSame($expected, array_map(static fn (Request $request) => $request->getMethod(), $this->requests()));
    }

    public function testListsSortedZonesAsJson(): void
    {
        $this->mock([$this->jsonResponse(['z.example', 'a.example'])]);
        $tester = $this->runCommand('zone:list', ['--json' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(['a.example', 'z.example'], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        self::assertSame('/1.0/domain/zone', $this->requests()[0]->getUri()->getPath());
    }

    public function testExpandsRecordIdsAndPassesFiltersIncludingRoot(): void
    {
        $this->mock([
            $this->jsonResponse([43, 42]),
            $this->jsonResponse($this->record(42, ['fieldType' => 'TXT', 'subDomain' => ''])),
            $this->jsonResponse($this->record(43, ['fieldType' => 'TXT', 'subDomain' => ''])),
        ]);
        $tester = $this->runCommand('record:list', ['zone' => 'EXAMPLE.COM.', '--type' => 'txt', '--name' => '@', '--json' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $records = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([42, 43], array_column($records, 'id'));
        self::assertSame('TXT', $records[0]['fieldType']);
        parse_str($this->requests()[0]->getUri()->getQuery(), $filters);
        self::assertSame(['fieldType' => 'TXT', 'subDomain' => ''], $filters);
        self::assertSame('/1.0/domain/zone/example.com/record/42', $this->requests()[1]->getUri()->getPath());
    }

    public function testEmptyListIsJsonArray(): void
    {
        $this->mock([$this->jsonResponse([])]);
        $tester = $this->runCommand('record:list', ['zone' => 'example.com', '--json' => true]);
        self::assertSame("[]\n", $tester->getDisplay());
        $this->assertMethods(['GET']);
    }

    public function testWildcardFilterIsLiteralEvenIfApiReturnsLikeMatches(): void
    {
        $this->mock([
            $this->jsonResponse([42, 43]),
            $this->jsonResponse($this->record(42, ['subDomain' => 'www'])),
            $this->jsonResponse($this->record(43, ['subDomain' => '*'])),
        ]);
        $tester = $this->runCommand('record:list', ['zone' => 'example.com', '--name' => '*', '--json' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame([43], array_column(json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR), 'id'));
    }

    public function testRecordShowPreservesJsonAndTableData(): void
    {
        $record = $this->record(42, ['target' => '"<info>tekst</info>"', 'subDomain' => '0']);
        $this->mock([$this->jsonResponse($record), $this->jsonResponse($record)]);
        $tester = $this->runCommand('record:show', ['zone' => 'example.com', 'id' => '42', '--json' => true]);
        self::assertSame($record, json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
        $table = $this->runCommand('record:show', ['zone' => 'example.com', 'id' => '42']);
        self::assertStringContainsString('<info>tekst</info>', $table->getDisplay());
        self::assertStringNotContainsString('@', $table->getDisplay());
    }

    public function testExportIsUnmodifiedZoneText(): void
    {
        $zone = '$TTL 3600'."\n@ IN TXT \"<info>literal</info>\"\n";
        $this->mock([$this->jsonResponse($zone)]);
        $tester = $this->runCommand('zone:export', ['zone' => 'example.com']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame($zone, $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testCreateUsesRootDefaultTtlAndPublishesAfterSaving(): void
    {
        $this->mockWrite([$this->jsonResponse($this->record()), $this->jsonResponse(null)]);
        $tester = $this->runCommand('record:add', ['zone' => 'example.com', '--type' => 'a', '--name' => '@', '--target' => '203.0.113.10']);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'POST', 'POST']);
        self::assertSame(['fieldType' => 'A', 'subDomain' => '', 'target' => '203.0.113.10', 'ttl' => 0], json_decode((string) $this->mutationRequests()[0]->getBody(), true));
        self::assertSame('/1.0/domain/zone/example.com/refresh', $this->mutationRequests()[1]->getUri()->getPath());
        self::assertStringContainsString('Saved record #42', $tester->getDisplay());
        self::assertStringContainsString('accepted publication', $tester->getDisplay());
    }

    public function testUpdateSendsOnlyChangedFieldAndAcceptsTtlZero(): void
    {
        $this->mockWrite([$this->jsonResponse(null), $this->jsonResponse(null)]);
        $tester = $this->runCommand('record:update', ['zone' => 'example.com', 'id' => '42', '--ttl' => '0']);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'PUT', 'POST']);
        self::assertSame(['ttl' => 0], json_decode((string) $this->mutationRequests()[0]->getBody(), true));
    }

    public function testTxtTargetIsNotRequotedOrTrimmed(): void
    {
        $this->mockWrite([$this->jsonResponse(null)]);
        $target = '"v=spf1 include:example.com ~all"';
        $tester = $this->runCommand('record:update', ['zone' => 'example.com', 'id' => '42', '--name' => '@', '--target' => $target, '--no-refresh' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'PUT']);
        self::assertSame(['subDomain' => '', 'target' => $target], json_decode((string) $this->mutationRequests()[0]->getBody(), true));
        self::assertStringContainsString('zone:refresh example.com', $tester->getDisplay());
    }

    public static function deferredWrites(): array
    {
        return [
            'create' => ['record:add', ['--type' => 'A', '--name' => 'www', '--target' => '203.0.113.10'], ['POST']],
            'update' => ['record:update', ['id' => '42', '--target' => '203.0.113.11'], ['PUT']],
            'delete' => ['record:delete', ['id' => '42', '--force' => true], ['GET', 'DELETE']],
        ];
    }

    #[DataProvider('deferredWrites')]
    public function testNoRefreshDefersPublication(string $name, array $arguments, array $methods): void
    {
        $responses = $name === 'record:delete' ? [$this->jsonResponse($this->record()), $this->jsonResponse(null)] : [$this->jsonResponse($this->record())];
        $this->mockWrite($responses, $name === 'record:delete');
        $tester = $this->runCommand($name, ['zone' => 'example.com', '--no-refresh' => true, ...$arguments]);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods($name === 'record:delete' ? ['GET', 'GET', 'GET', 'GET', 'DELETE'] : ['GET', 'GET', ...$methods]);
        self::assertStringContainsString('pending publication', $tester->getDisplay());
    }

    public function testDeleteForceReadsExactRecordThenDeletesAndPublishes(): void
    {
        $this->mockWrite([$this->jsonResponse($this->record()), $this->jsonResponse(null), $this->jsonResponse(null)], true);
        $tester = $this->runCommand('record:delete', ['zone' => 'example.com', 'id' => '42', '--force' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'GET', 'GET', 'DELETE', 'POST']);
        self::assertSame('/1.0/domain/zone/example.com/record/42', $this->mutationRequests()[0]->getUri()->getPath());
    }

    public function testCancelledDeletionNeverWrites(): void
    {
        $this->mock([$this->jsonResponse($this->record())]);
        $tester = $this->runCommand('record:delete', ['zone' => 'example.com', 'id' => '42'], ['no'], true);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Cancelled', $tester->getDisplay());
        $this->assertMethods(['GET']);
    }

    public function testConfirmedDeletionWrites(): void
    {
        $this->mockWrite([$this->jsonResponse($this->record()), $this->jsonResponse(null), $this->jsonResponse(null)], true);
        $tester = $this->runCommand('record:delete', ['zone' => 'example.com', 'id' => '42'], ['yes'], true);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'GET', 'GET', 'DELETE', 'POST']);
    }

    public function testNonInteractiveDeleteRequiresForceBeforeAnyRequest(): void
    {
        $this->mock();
        $tester = $this->runCommand('record:delete', ['zone' => 'example.com', 'id' => '42']);
        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('--force', $tester->getErrorOutput());
        self::assertSame([], $this->history);
    }

    public static function dryRuns(): array
    {
        return [
            ['record:add', ['--type' => 'TXT', '--name' => '_test', '--target' => '"test"']],
            ['record:update', ['id' => '42', '--ttl' => '600']],
            ['zone:refresh', []],
        ];
    }

    #[DataProvider('dryRuns')]
    public function testDryRunDoesNotEvenConstructApiClient(string $name, array $arguments): void
    {
        // No injected SDK and no credentials are needed for these previews.
        $tester = new CommandTester(ApplicationFactory::create()->find($name));
        $tester->execute(['zone' => 'example.com', '--dry-run' => true, ...$arguments]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('DRY RUN', $tester->getDisplay());
    }

    public function testDeleteDryRunOnlyReadsAndDoesNotNeedForce(): void
    {
        $this->mock([$this->jsonResponse($this->record())]);
        $tester = $this->runCommand('record:delete', ['zone' => 'example.com', 'id' => '42', '--dry-run' => true]);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET']);
        self::assertStringContainsString('203.0.113.10', $tester->getDisplay());
    }

    public function testExplicitRefreshPublishesOnce(): void
    {
        $this->mockWrite([$this->jsonResponse(null)]);
        $tester = $this->runCommand('zone:refresh', ['zone' => 'example.com']);
        self::assertSame(0, $tester->getStatusCode());
        $this->assertMethods(['GET', 'GET', 'POST']);
        self::assertSame('/1.0/domain/zone/example.com/refresh', $this->mutationRequests()[0]->getUri()->getPath());
    }

    public static function invalidInputs(): array
    {
        return [
            ['record:add', ['--type' => 'A', '--name' => '@']],
            ['record:add', ['--type' => 'INVALID', '--name' => '@', '--target' => 'x']],
            ['record:add', ['--type' => 'A', '--name' => 'www.', '--target' => 'x']],
            ['record:update', ['id' => '42']],
            ['record:update', ['id' => '42', '--ttl' => '-1']],
            ['record:update', ['id' => '42', '--ttl' => '2147483648']],
            ['record:update', ['id' => '42', '--ttl' => '1.5']],
            ['record:update', ['id' => '42', '--target' => '']],
            ['record:list', ['--type' => 'INVALID']],
            ['record:show', ['id' => '../42']],
            ['record:show', ['id' => '0']],
            ['zone:export', ['zone' => 'example.com/record']],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsExitTwoWithoutRequests(string $name, array $arguments): void
    {
        $this->mock();
        $tester = $this->runCommand($name, array_replace(['zone' => 'example.com'], $arguments));
        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay().$tester->getErrorOutput());
        self::assertSame([], $this->history);
    }

    public static function failedReads(): array
    {
        return [[400], [401], [403], [404], [409], [429], [500]];
    }

    #[DataProvider('failedReads')]
    public function testApiErrorsAreSafeEvenInDebugAndDoNotContaminateStdout(int $status): void
    {
        $this->mock([$this->jsonResponse(['message' => 'test-application-secret test-consumer-key'], $status)]);
        $tester = $this->runCommand('record:show', ['zone' => 'example.com', 'id' => '42', '--json' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        $error = $tester->getErrorOutput();
        self::assertStringContainsString('HTTP '.$status, $error);
        self::assertStringNotContainsString('test-application', $error);
        self::assertStringNotContainsString('test-consumer', $error);
        self::assertStringNotContainsString('X-Ovh', $error);
        self::assertStringNotContainsString('Stack trace', $error);
        $this->assertMethods(['GET']);
    }

    public function testFailedRefreshReportsSuccessfulSaveAndDoesNotRetry(): void
    {
        $this->mockWrite([$this->jsonResponse($this->record()), $this->jsonResponse(['message' => 'test-application-secret'], 403)]);
        $tester = $this->runCommand('record:add', ['zone' => 'example.com', '--type' => 'A', '--name' => 'www', '--target' => '203.0.113.10']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Saved record #42', $tester->getDisplay());
        self::assertStringContainsString('Do not repeat the record write', $tester->getErrorOutput());
        self::assertStringContainsString('zone:refresh example.com', $tester->getErrorOutput());
        self::assertStringNotContainsString('test-application', $tester->getErrorOutput());
        $this->assertMethods(['GET', 'GET', 'POST', 'POST']);
    }

    public function testWriteTimeoutHasUnknownOutcomeAndIsNotRetriedOrPublished(): void
    {
        $this->mockWrite([new ConnectException('test-application-secret', new Request('POST', 'https://eu.api.ovh.com'))]);
        $tester = $this->runCommand('record:update', ['zone' => 'example.com', 'id' => '42', '--ttl' => '60']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Operation outcome may be unknown', $tester->getErrorOutput());
        self::assertStringNotContainsString('test-application', $tester->getErrorOutput());
        $this->assertMethods(['GET', 'GET', 'PUT']);
    }

    public function testUnexpectedTransportExceptionNeverLeaksItsMessage(): void
    {
        $this->mock([new \InvalidArgumentException('test-application-secret')]);
        $tester = $this->runCommand('zone:list');
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Unexpected error', $tester->getErrorOutput());
        self::assertStringNotContainsString('test-application', $tester->getErrorOutput());
    }

    public static function parserErrors(): array
    {
        return [
            [['command' => 'record:show', 'zone' => 'example.com']],
            [['command' => 'record:update', 'zone' => 'example.com', 'id' => '42', '--type' => 'TXT']],
            [['command' => 'missing:command']],
        ];
    }

    #[DataProvider('parserErrors')]
    public function testArgumentParserErrorsUseExitTwo(array $input): void
    {
        $this->mock();
        $app = ApplicationFactory::create($this->client, $this->directory);
        $app->setAutoExit(false);
        $tester = new ApplicationTester($app);
        $tester->run($input, ['interactive' => false]);
        self::assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame([], $this->history);
    }
}
