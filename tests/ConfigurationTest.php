<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use OvhDns\Configuration;
use OvhDns\ConfigurationException;
use OvhDns\Console\ApplicationFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ConfigurationTest extends TestCase
{
    private const VARIABLES = ['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY', 'OVH_ENDPOINT'];

    private array $environment;
    private array $server;
    private array $process;
    private string $directory;

    protected function setUp(): void
    {
        $this->environment = $_ENV;
        $this->server = $_SERVER;
        foreach (self::VARIABLES as $name) {
            $this->process[$name] = getenv($name);
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->directory = sys_get_temp_dir().'/zone-harbor-config-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->process as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        $_ENV = $this->environment;
        $_SERVER = $this->server;
        if (is_file($this->directory.'/.env.local')) {
            unlink($this->directory.'/.env.local');
        }
        rmdir($this->directory);
    }

    private function writeLocalEnvironment(string $extra = ''): void
    {
        file_put_contents($this->directory.'/.env.local', "OVH_APPLICATION_KEY=file-key\nOVH_APPLICATION_SECRET=file-secret\nOVH_CONSUMER_KEY=file-consumer\n".$extra);
    }

    public function testLocalFileWorksWithoutARegularDotenvFile(): void
    {
        $this->writeLocalEnvironment();
        Configuration::loadEnvironment($this->directory);
        // Constructing the real SDK does not perform any network request.
        self::assertSame('file-consumer', Configuration::createApi()->getConsumerKey());
    }

    public function testProcessEnvironmentTakesPrecedence(): void
    {
        $this->writeLocalEnvironment();
        putenv('OVH_CONSUMER_KEY=process-consumer');
        Configuration::loadEnvironment($this->directory);
        self::assertSame('process-consumer', Configuration::createApi()->getConsumerKey());
    }

    public function testEmptyProcessValueDoesNotFallBackToFileSecret(): void
    {
        $this->writeLocalEnvironment();
        putenv('OVH_CONSUMER_KEY=');
        Configuration::loadEnvironment($this->directory);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Missing configuration OVH_CONSUMER_KEY');
        Configuration::createApi();
    }

    public function testUnknownEndpointCannotSendKeysToAnArbitraryHost(): void
    {
        $this->writeLocalEnvironment('OVH_ENDPOINT=https://example.com');
        Configuration::loadEnvironment($this->directory);
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('OVH_ENDPOINT must be');
        Configuration::createApi();
    }

    public function testMalformedFileDoesNotLeakItsContents(): void
    {
        file_put_contents($this->directory.'/.env.local', 'OVH_APPLICATION_SECRET="private-test-value');
        try {
            Configuration::loadEnvironment($this->directory);
            self::fail('Malformed Dotenv file was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('Check its syntax', $exception->getMessage());
            self::assertStringNotContainsString('private-test-value', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function testHelpAndListWorkWithoutCredentials(): void
    {
        Configuration::loadEnvironment($this->directory);
        $app = ApplicationFactory::create();
        $tester = new CommandTester($app->find('list'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('record:add', $tester->getDisplay());
        $help = new CommandTester($app->find('help'));
        $help->execute(['command_name' => 'record:delete']);
        self::assertSame(0, $help->getStatusCode());
        self::assertStringContainsString('--force', $help->getDisplay());
    }

    public function testMissingCredentialsFailCleanly(): void
    {
        $tester = new CommandTester(ApplicationFactory::create()->find('zone:list'));
        $tester->execute(['--json' => true], ['capture_stderr_separately' => true]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('OVH_APPLICATION_KEY', $tester->getErrorOutput());
    }
}
