<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use OvhDns\Configuration;
use OvhDns\Dns\DnsService;
use OvhDns\Onboarding\{ConfigStore, PrivateFile, UserConfiguration};
use OvhDns\Operation\{WriteExecutor, WritePreparationException};
use OvhDns\Tests\Support\MemoryProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/Support/HealthDoubles.php';

final class OnboardingTest extends TestCase
{
    private string $directory;
    private string $config;
    private array $environment;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/zone-harbor-onboarding-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->config = $this->directory.'/profile/config.yaml';
        $this->environment = ['ZONE_HARBOR_CONFIG' => false, 'XDG_CONFIG_HOME' => $this->directory.'/xdg'];
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY', 'OVH_ENDPOINT', 'MCP_HTTP_TOKEN', 'APP_SECRET'] as $key) {
            $this->environment[$key] = false; $this->environment[$key.'_FILE'] = false;
        }
    }
    protected function tearDown(): void
    {
        Configuration::reset();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->directory);
    }
    private function command(array $args, array $environment = []): Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/bin/zone-harbor', ...$args], sys_get_temp_dir(), $environment + $this->environment);
        $process->setTimeout(25); $process->run(); return $process;
    }
    private function initializeProfile(): void
    {
        $result = $this->command(['setup', '--config', $this->config, '--mode=health', '--domain=EXAMPLE.COM.', '--skip-checks', '-n']);
        self::assertSame(0, $result->getExitCode(), $result->getOutput().$result->getErrorOutput());
    }
    public function testSetupOutsideCheckoutAndGlobalConfigBeforeOrAfterCommand(): void
    {
        $this->initializeProfile();
        self::assertSame('health', UserConfiguration::load($this->config)->mode());
        self::assertSame('example.com', UserConfiguration::load($this->config)->data['default_domain']);
        self::assertSame(0600, fileperms($this->config) & 0777); self::assertSame(0700, fileperms(dirname($this->config)) & 0777);
        $validate = $this->command(['--config', $this->config, 'config:validate']);
        self::assertSame(0, $validate->getExitCode(), $validate->getErrorOutput());
        self::assertSame(0, $this->command(['config:validate'], ['ZONE_HARBOR_CONFIG' => $this->config])->getExitCode());
        self::assertSame(0, $this->command(['record:add', '--config', $this->config, 'example.com', '--type=TXT', '--name=_demo', '--target="demo"', '--dry-run'])->getExitCode());
    }
    public function testDefaultXdgPathAndNonInteractiveSetupRequiresExplicitMode(): void
    {
        $missing = $this->command(['setup', '-n']); self::assertSame(2, $missing->getExitCode());
        $result = $this->command(['setup', '-n', '--mode=health', '--skip-checks']);
        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        self::assertFileExists($this->directory.'/xdg/zone-harbor/config.yaml');
    }
    public function testRepeatedSetupPreservesHealthSettingsAndCreatesPrivateBackup(): void
    {
        $this->initializeProfile();
        $data = UserConfiguration::load($this->config)->data;
        $data['health'] = ['certificate' => ['warning_remaining' => .4], 'domains' => ['example.com' => ['dkim_selectors' => ['google'], 'endpoints' => [['host' => 'mail.example.com', 'port' => 993]]]]];
        file_put_contents($this->config, Yaml::dump($data, 8));
        $before = file_get_contents($this->config);
        $result = $this->command(['setup', '--config', $this->config, '-n', '--mode=health', '--domain=other.example.com', '--skip-checks']);
        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        self::assertSame($data['health'], UserConfiguration::load($this->config)->data['health']);
        $backups = glob($this->config.'.zone-harbor-*.bak'); self::assertCount(1, $backups);
        self::assertSame($before, file_get_contents($backups[0])); self::assertSame(0600, fileperms($backups[0]) & 0777);
    }
    public function testCredentialsNeverAppearInOutputOrGeneratedClientSettings(): void
    {
        $credentials = ['OVH_APPLICATION_KEY' => 'test-private-key', 'OVH_APPLICATION_SECRET' => "test-private-'\\\"\$value", 'OVH_CONSUMER_KEY' => 'test-private-consumer'];
        $result = $this->command(['setup', '--config', $this->config, '-n', '--mode=ovh', '--skip-checks'], $credentials);
        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput());
        $user = UserConfiguration::load($this->config);
        self::assertSame($credentials, (new Dotenv())->parse(file_get_contents($user->credentialsFile())));
        self::assertSame(0600, fileperms($user->credentialsFile()) & 0777);
        foreach ($credentials as $secret) self::assertStringNotContainsString($secret, $result->getOutput().$result->getErrorOutput().file_get_contents($this->config));
        $generated = $this->command(['--config', $this->config, 'mcp:config']);
        self::assertSame(0, $generated->getExitCode(), $generated->getErrorOutput());
        $json = json_decode($generated->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(realpath(PHP_BINARY), realpath($json['mcpServers']['zone-harbor']['command']));
        self::assertSame($this->config, $json['mcpServers']['zone-harbor']['args'][2]);
        foreach ($credentials as $secret) self::assertStringNotContainsString($secret, $generated->getOutput().$generated->getErrorOutput());
        $doctor = $this->command(['--config', $this->config, 'doctor', '--json']);
        self::assertSame(0, $doctor->getExitCode(), $doctor->getOutput());
        foreach ($credentials as $secret) self::assertStringNotContainsString($secret, $doctor->getOutput().$doctor->getErrorOutput());
    }
    public function testClientPreviewDoesNotWriteAndMergePreservesUnrelatedSecrets(): void
    {
        $this->initializeProfile(); $file = $this->directory.'/client.json';
        $before = '{"theme":"dark","mcpServers":{"other":{"env":{"TOKEN":"test-unrelated-value"}}}}';
        file_put_contents($file, $before);
        $preview = $this->command(['--config', $this->config, 'mcp:config', '--client=cursor', '--output', $file]);
        self::assertSame('stdio', json_decode($preview->getOutput(), true)['mcpServers']['zone-harbor']['type']);
        self::assertSame(0, $preview->getExitCode()); self::assertSame($before, file_get_contents($file));
        self::assertStringNotContainsString('test-unrelated-value', $preview->getOutput());
        $write = $this->command(['--config', $this->config, 'mcp:config', '--output', $file, '--write']);
        self::assertSame(0, $write->getExitCode(), $write->getErrorOutput());
        $saved = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('dark', $saved['theme']); self::assertSame('test-unrelated-value', $saved['mcpServers']['other']['env']['TOKEN']);
        self::assertArrayHasKey('zone-harbor', $saved['mcpServers']); self::assertSame(0600, fileperms($file) & 0777);
        $backups = glob($file.'.zone-harbor-*.bak'); self::assertCount(1, $backups); self::assertSame($before, file_get_contents($backups[0]));
        self::assertStringNotContainsString('test-unrelated-value', $write->getOutput().$write->getErrorOutput());
    }
    public function testMalformedAndSymlinkClientConfigurationsArePreserved(): void
    {
        $this->initializeProfile(); $file = $this->directory.'/broken.json'; file_put_contents($file, '{test-secret-value');
        $result = $this->command(['--config', $this->config, 'mcp:config', '--output', $file, '--write']);
        self::assertSame(2, $result->getExitCode()); self::assertSame('{test-secret-value', file_get_contents($file));
        self::assertStringNotContainsString('test-secret-value', $result->getErrorOutput());
        $link = $this->directory.'/link.json'; symlink($file, $link);
        self::assertSame(1, $this->command(['--config', $this->config, 'mcp:config', '--output', $link, '--write'])->getExitCode());
        self::assertSame('{test-secret-value', file_get_contents($file));
    }
    public function testConfigValidationAndDoctorDoNotLeakMalformedFileContents(): void
    {
        mkdir(dirname($this->config)); file_put_contents($this->config, 'version: "test-secret-value');
        foreach ([['config:validate'], ['doctor', '--json']] as $command) {
            $result = $this->command(['--config', $this->config, ...$command]);
            self::assertNotSame(0, $result->getExitCode()); self::assertStringNotContainsString('test-secret-value', $result->getOutput().$result->getErrorOutput());
        }
        self::assertSame(0, $this->command(['--config', $this->config, 'help', 'setup'])->getExitCode());
    }
    public function testHealthModeCannotMutateEvenWithAnInjectedProvider(): void
    {
        $this->initializeProfile(); $old = getenv('ZONE_HARBOR_CONFIG');
        try {
            Configuration::initialize(dirname(__DIR__), $this->config);
            $provider = new MemoryProvider();
            $executor = new WriteExecutor(new DnsService($provider), Configuration::operationDirectory(...));
            try { $executor->run('record:add', 'example.com', fields: ['fieldType' => 'TXT', 'subDomain' => '_demo', 'target' => '"test"'], dryRun: false); self::fail('Health mode wrote DNS.'); }
            catch (WritePreparationException $e) { self::assertStringContainsString('disabled', $e->getMessage()); }
            self::assertSame(0, $provider->writes); self::assertDirectoryDoesNotExist(dirname($this->config).'/state/operations');
        } finally { Configuration::reset(); $old === false ? putenv('ZONE_HARBOR_CONFIG') : putenv('ZONE_HARBOR_CONFIG='.$old); unset($_ENV['ZONE_HARBOR_CONFIG']); }
    }
    public function testGeneratedMcpEntryWorksFromAnotherDirectoryAndHasNoWizardBanner(): void
    {
        $this->initializeProfile(); $generated = $this->command(['--config', $this->config, 'mcp:config']);
        $entry = json_decode($generated->getOutput(), true, flags: JSON_THROW_ON_ERROR)['mcpServers']['zone-harbor'];
        $client = Client::builder()->setClientInfo('onboarding-test', '1')->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport($entry['command'], $entry['args'], sys_get_temp_dir(), env: $this->environment));
            self::assertSame('zone-harbor', $client->getServerInfo()->name);
            self::assertCount(10, $client->listTools()->tools);
            $result = $client->callTool('ovh_record_add', ['zone' => 'example.com', 'type' => 'TXT', 'name' => '_demo', 'target' => '"test"', 'dryRun' => false]);
            self::assertTrue($result->isError); self::assertSame('failed', $result->structuredContent['status']);
            self::assertDirectoryDoesNotExist(dirname($this->config).'/state/operations');
        } finally { $client->disconnect(); }
        self::assertDirectoryExists(dirname($this->config).'/state/cache');
        $missing = $this->command(['--config', $this->directory.'/missing.yaml', 'mcp:serve']);
        self::assertSame(1, $missing->getExitCode()); self::assertSame('', $missing->getOutput());
    }
    public function testDoctorChecksLocalMcpWithReadOnlyPublicConfiguration(): void
    {
        $this->initializeProfile(); $result = $this->command(['--config', $this->config, 'doctor', '--online', '--json']);
        self::assertSame(0, $result->getExitCode(), $result->getOutput().$result->getErrorOutput());
        $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $statuses = []; foreach ($report['checks'] as $check) $statuses[$check['id']] = $check['status'];
        self::assertSame('PASS', $statuses['mcp']); self::assertSame('SKIP', $statuses['ovh.credentials']);
    }
    public function testDoctorReportsAFileBlockingTheStateDirectory(): void
    {
        $this->initializeProfile(); file_put_contents(dirname($this->config).'/state', 'a file blocks storage');
        $result = $this->command(['--config', $this->config, 'doctor', '--json']); self::assertSame(1, $result->getExitCode());
        $report = json_decode($result->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $storage = array_values(array_filter($report['checks'], static fn (array $check): bool => $check['id'] === 'storage'));
        self::assertSame('FAIL', $storage[0]['status']);
    }
    public function testEmbeddedApplicationSupportsConfigurationCommands(): void
    {
        $this->initializeProfile();
        $tester = new \Symfony\Component\Console\Tester\CommandTester(\OvhDns\Console\ApplicationFactory::create()->find('config:validate'));
        $tester->execute(['--config' => $this->config]); self::assertSame(0, $tester->getStatusCode());
    }

    public function testTuiWorkerInheritsPublicConfigurationAndRejectsWrites(): void
    {
        $this->initializeProfile();
        $worker = new Process([PHP_BINARY, dirname(__DIR__).'/bin/dns-worker'], sys_get_temp_dir(), ['ZONE_HARBOR_CONFIG' => $this->config] + $this->environment);
        $worker->setInput(json_encode(['action' => 'write', 'operation' => 'record:add', 'zone' => 'example.com', 'fields' => ['fieldType' => 'TXT', 'subDomain' => '_demo', 'target' => '"test"']], JSON_THROW_ON_ERROR));
        $worker->mustRun(); $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('failed', $result['status']); self::assertStringContainsString('disabled', $result['error']);
        self::assertDirectoryDoesNotExist(dirname($this->config).'/state/operations');
    }
    public function testSchemaRejectsUnsupportedVersionsAndInlineSecrets(): void
    {
        mkdir(dirname($this->config));
        foreach ([['version' => 2, 'mode' => 'health'], ['version' => 1, 'mode' => 'ovh', 'ovh' => ['application_secret' => 'test-inline-value']], ['version' => 1, 'mode' => 'health', 'health' => ['certificate' => ['warning_remaining' => .05, 'critical_remaining' => .1]]]] as $data) {
            file_put_contents($this->config, Yaml::dump($data, 8));
            $result = $this->command(['config:validate', '--config', $this->config]);
            self::assertSame(2, $result->getExitCode()); self::assertStringNotContainsString('test-inline-value', $result->getErrorOutput());
        }
    }

    public function testInstallerCreatesAWorkingShortcutAndPreservesExistingFiles(): void
    {
        $bin = $this->directory.'/bin';
        $result = $this->command(['install', '--bin-dir', $bin]);
        self::assertSame(0, $result->getExitCode(), $result->getErrorOutput()); self::assertTrue(is_link($bin.'/zone-harbor'));
        $process = new Process([$bin.'/zone-harbor', '--version'], sys_get_temp_dir(), $this->environment); $process->mustRun();
        self::assertStringContainsString('ZoneHarbor', $process->getOutput());
        self::assertSame(0, $this->command(['install', '--bin-dir', $bin])->getExitCode());
        unlink($bin.'/zone-harbor'); file_put_contents($bin.'/zone-harbor', 'keep this file');
        self::assertSame(2, $this->command(['install', '--bin-dir', $bin, '--replace'])->getExitCode());
        self::assertSame('keep this file', file_get_contents($bin.'/zone-harbor'));
    }
    public function testDockerMcpEntryUsesThePrivateVolumeWithoutEmbeddingCredentials(): void
    {
        $entry = (new \OvhDns\Onboarding\McpClientConfiguration('docker', '/opt/example/docker', 'zone-harbor:test', 'test_config'))->entry('/app/user-config/config.yaml');
        self::assertSame('/opt/example/docker', $entry['command']);
        self::assertContains('type=volume,source=test_config,target=/app/user-config', $entry['args']);
        self::assertContains('--read-only', $entry['args']); self::assertContains('mcp:serve', $entry['args']);
        self::assertNotContains('-t', $entry['args']); self::assertNotContains('-it', $entry['args']);
        $this->expectException(\InvalidArgumentException::class);
        (new \OvhDns\Onboarding\McpClientConfiguration('docker'))->entry('/tmp/host-only/config.yaml');
    }
    public function testAllShippedExamplesValidateWithoutCredentialsOrNetwork(): void
    {
        $root = dirname(__DIR__);
        self::assertSame('health', UserConfiguration::load($root.'/zone-harbor.example.yaml')->mode());
        foreach (glob($root.'/examples/config/*.yaml') as $file) {
            if (basename($file) === 'legacy-health.yaml') self::assertSame(.25, \OvhDns\Health\HealthConfig::load($file)->warning);
            else self::assertSame(basename($file) === 'ovh.yaml' ? 'ovh' : 'health', UserConfiguration::load($file)->mode());
        }
    }

    public function testStaleWizardCannotOverwriteAnInterveningEdit(): void
    {
        $this->initializeProfile(); $before = file_get_contents($this->config); file_put_contents($this->config, $before."\n# another edit\n");
        try { (new ConfigStore())->save($this->config, ['version' => 1, 'mode' => 'health'], null, hash('sha256', $before)); self::fail('Stale save accepted.'); }
        catch (\RuntimeException $e) { self::assertStringContainsString('changed during setup', $e->getMessage()); }
        self::assertStringContainsString('# another edit', file_get_contents($this->config));
    }
}
