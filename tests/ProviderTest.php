<?php

declare(strict_types=1);

namespace OvhDns\Tests;

require_once __DIR__.'/Support/HealthDoubles.php';

use OvhDns\Dns\DnsService;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Provider\{Mutation, Record};
use OvhDns\Tests\Support\MemoryProvider;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    public function testOpaqueReferencesExactRdataAndDefaultTtlRoundTrip(): void
    {
        $provider = new MemoryProvider(); $dns = new DnsService($provider);
        $target = '" v=spf1 -all "';
        $record = $dns->createRecord('EXAMPLE.COM.', ['fieldType' => 'TXT', 'subDomain' => '@', 'target' => $target, 'ttl' => 0]);
        self::assertSame('opaque:new', $record['id']); self::assertSame($target, $record['target']);
        self::assertNull($provider->record('example.com', 'opaque:new')->ttl);
        $dns->updateRecord('example.com', 'opaque:new', ['ttl' => 300]);
        self::assertSame($target, $dns->getRecord('example.com', 'opaque:new')['target']);
        self::assertSame(300, $provider->record('example.com', 'opaque:new')->ttl);
        self::assertSame('opaque:new', $dns->plan('record:delete', 'example.com', 'opaque:new')['id']);
        $dns->deleteRecord('example.com', 'opaque:new'); self::assertSame([], $provider->data);
    }
    public function testUnsupportedRecordTypeAndExportAreExplicit(): void
    {
        $dns = new DnsService(new MemoryProvider());
        try { $dns->exportZone('example.com'); self::fail('Unsupported export accepted.'); }
        catch (\OvhDns\Provider\UnsupportedCapability $e) { self::assertStringContainsString('does not support', $e->getMessage()); }
        $this->expectException(\InvalidArgumentException::class);
        $dns->plan('record:add', 'example.com', fields: ['fieldType' => 'MX', 'subDomain' => '@', 'target' => '10 mail.example.com.']);
    }
    public function testNormalizedBackupWithoutNativeExportPrecedesWrite(): void
    {
        $provider = new MemoryProvider();
        $provider->data['opaque:existing'] = new Record('opaque:existing', 'example.com', 'TXT', '_test', '"preserve me"', null, ['providerFlag' => true]);
        $directory = sys_get_temp_dir().'/zone-harbor-contract-'.bin2hex(random_bytes(8));
        try {
            $result = (new WriteExecutor(new DnsService($provider), $directory))->run('record:add', 'example.com', fields: ['fieldType' => 'A', 'subDomain' => '@', 'target' => '203.0.113.10'], dryRun: false);
            self::assertSame('publication_accepted', $result['status']); self::assertSame(1, $provider->writes);
            $backup = json_decode(file_get_contents($result['backup'].'/before.json'), true, flags: JSON_THROW_ON_ERROR);
            self::assertCount(1, $backup); self::assertSame('opaque:existing', $backup[0]['reference']);
            self::assertSame(['providerFlag' => true], $backup[0]['metadata']); self::assertNull($backup[0]['ttl']);
            self::assertFileDoesNotExist($result['backup'].'/before.zone'); self::assertFileExists($result['backup'].'/intent.json');
            self::assertSame(0600, fileperms($result['backup'].'/before.json') & 0777);
        } finally {
            if (is_dir($directory)) {
                $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
                rmdir($directory);
            }
        }
    }
    public function testPartialMutationKeepsOmittedFieldsAndDistinguishesDefaultTtl(): void
    {
        self::assertSame(['ttl' => null], Mutation::fromLegacy(['ttl' => 0])->fields);
        self::assertSame(['target' => '" literal "'], Mutation::fromLegacy(['target' => '" literal "'])->legacy());
    }
}
