<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use OvhDns\Configuration;
use PHPUnit\Framework\TestCase;

final class DockerSecretsTest extends TestCase
{
    public function testSetupPreservesSecretsAndDoesNotPrintValues(): void
    {
        $directory = sys_get_temp_dir().'/ovh-secret-test-'.bin2hex(random_bytes(8));
        mkdir($directory.'/bin', 0700, true);
        symlink(dirname(__DIR__).'/vendor', $directory.'/vendor');
        copy(dirname(__DIR__).'/bin/setup-docker-secrets', $directory.'/bin/setup-docker-secrets');
        $environment = array_merge(getenv(), [
            'OVH_APPLICATION_KEY' => 'test-secret-key',
            'OVH_APPLICATION_SECRET' => 'test-secret-value',
            'OVH_CONSUMER_KEY' => 'test-secret-consumer',
        ]);
        try {
            $first = null;
            for ($run = 0; $run < 2; ++$run) {
                $process = proc_open([PHP_BINARY, $directory.'/bin/setup-docker-secrets'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $directory, $environment);
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]);
                self::assertSame(0, proc_close($process));
                self::assertStringNotContainsString('test-secret-', $output);
                $token = trim(file_get_contents($directory.'/secrets/mcp_http_token'));
                self::assertSame(64, strlen($token));
                self::assertStringNotContainsString($token, $output);
                if ($first !== null) self::assertSame($first, $token);
                $first = $token;
            }
            self::assertSame(0700, fileperms($directory.'/secrets') & 0777);
            self::assertSame(0444, fileperms($directory.'/secrets/ovh_application_key') & 0777);
            self::assertSame('test-secret-key', trim(file_get_contents($directory.'/secrets/ovh_application_key')));
        } finally {
            foreach (glob($directory.'/secrets/*') as $file) unlink($file);
            rmdir($directory.'/secrets');
            unlink($directory.'/vendor');
            unlink($directory.'/bin/setup-docker-secrets');
            rmdir($directory.'/bin'); rmdir($directory);
        }
    }

    public function testFileSecretOverridesEnvironmentWithoutLeakingItsPath(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ovh-secret-');
        file_put_contents($file, "test-file-secret\n");
        $old = getenv('MCP_HTTP_TOKEN');
        $oldFile = getenv('MCP_HTTP_TOKEN_FILE');
        $oldEnv = $_ENV['MCP_HTTP_TOKEN'] ?? null;
        putenv('MCP_HTTP_TOKEN=old-test-value');
        putenv('MCP_HTTP_TOKEN_FILE='.$file);
        try {
            Configuration::loadSecretFiles();
            self::assertSame('test-file-secret', getenv('MCP_HTTP_TOKEN'));
            self::assertSame('test-file-secret', $_ENV['MCP_HTTP_TOKEN']);
        } finally {
            $old === false ? putenv('MCP_HTTP_TOKEN') : putenv('MCP_HTTP_TOKEN='.$old);
            $oldFile === false ? putenv('MCP_HTTP_TOKEN_FILE') : putenv('MCP_HTTP_TOKEN_FILE='.$oldFile);
            if ($oldEnv === null) unset($_ENV['MCP_HTTP_TOKEN']); else $_ENV['MCP_HTTP_TOKEN'] = $oldEnv;
            unlink($file);
        }
    }
}
