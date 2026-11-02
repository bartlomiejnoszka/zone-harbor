<?php

declare(strict_types=1);

namespace OvhDns\Tests;

use GuzzleHttp\Client as HttpClient;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;
use Mcp\Schema\Enum\ProtocolVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Full protocol round-trips, always preview-only and requiring no OVH credentials. */
final class TransportTest extends TestCase
{
    public function testStdioHandshakeDiscoveryAndPreviewWithOfficialClient(): void
    {
        $client = Client::builder()->setClientInfo('ovh-dns-test', '1.0')->setProtocolVersion(ProtocolVersion::V2025_11_25)->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $client->connect(new StdioTransport(PHP_BINARY, ['bin/mcp', 'mcp:server', 'default'], dirname(__DIR__)));
            $this->assertDiscoveryAndPreview($client);
        } finally { $client->disconnect(); }
    }

    public static function protocols(): array
    {
        return [[ProtocolVersion::V2025_11_25], [ProtocolVersion::V2026_07_28]];
    }

    #[DataProvider('protocols')]
    public function testHttpAuthHostOriginAndProtocol(ProtocolVersion $protocol): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $token = str_repeat('test-only-', 5);
        $root = dirname(__DIR__);
        // File logs are outside the repository; authentication headers are never printed.
        $log = tempnam(sys_get_temp_dir(), 'ovh-http-test-');
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', 'public', 'public/index.php'], [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $root, array_merge(getenv(), ['MCP_HTTP_TOKEN' => $token, 'APP_ENV' => 'test']));
        self::assertIsResource($process);
        $http = new HttpClient(['http_errors' => false, 'timeout' => 8]);
        $url = 'http://'.$address;
        $client = Client::builder()->setClientInfo('ovh-http-test', '1.0')->setProtocolVersion($protocol)->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        try {
            $ready = false;
            for ($attempt = 0; $attempt < 80; ++$attempt) {
                try { if ($http->get($url.'/healthz')->getStatusCode() === 200) { $ready = true; break; } } catch (\Throwable) {}
                usleep(50000);
            }
            self::assertTrue($ready, 'HTTP server did not become ready.');
            self::assertSame(401, $http->post($url.'/mcp')->getStatusCode());
            self::assertSame(401, $http->post($url.'/mcp', ['headers' => ['Authorization' => 'Bearer invalid']])->getStatusCode());
            $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json, text/event-stream'];
            self::assertSame(403, $http->post($url.'/mcp', ['headers' => $headers + ['Host' => 'evil.example']])->getStatusCode());
            self::assertSame(403, $http->post($url.'/mcp', ['headers' => $headers + ['Origin' => 'https://evil.example']])->getStatusCode());
            $client->connect(new HttpTransport($url.'/mcp', $headers));
            $this->assertDiscoveryAndPreview($client);
        } finally {
            $client->disconnect();
            proc_terminate($process);
            foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            proc_close($process);
            $contents = file_get_contents($log);
            unlink($log);
            self::assertStringNotContainsString($token, $contents);
        }
    }

    private function assertDiscoveryAndPreview(Client $client): void
    {
        $tools = $client->listTools()->tools;
        self::assertCount(10, $tools);
        foreach ($tools as $tool) {
            self::assertNotEmpty($tool->inputSchema);
            self::assertNotNull($tool->outputSchema);
            self::assertNotNull($tool->annotations->readOnlyHint);
        }
        $result = $client->callTool('ovh_record_add', ['zone' => 'example.com', 'type' => 'TXT', 'name' => '@', 'target' => '"demo"']);
        self::assertFalse($result->isError);
        self::assertSame('preview', $result->structuredContent['status']);
        self::assertSame('TXT', $result->structuredContent['fields']['fieldType']);
        $health = $client->callTool('tls_certificate_check', ['host' => '../invalid']);
        self::assertTrue($health->isError);
        self::assertSame('invalid_input', $health->structuredContent['status']);
        foreach ($tools as $tool) if (in_array($tool->name, ['dns_domain_check', 'tls_certificate_check'], true)) self::assertTrue($tool->annotations->readOnlyHint);
        $bad = $client->callTool('ovh_record_update', ['zone' => 'example.com', 'id' => '42']);
        self::assertTrue($bad->isError);
        self::assertSame('invalid_input', $bad->structuredContent['status']);
    }
}
