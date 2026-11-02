<?php

declare(strict_types=1);

use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\StdioTransport;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['http:', 'token-file:', 'docker', 'live-zone:']);
$client = Client::builder()->setClientInfo('ovh-dns-smoke', '1.0')->setMaxRetries(0)->setInitTimeout(15)->setRequestTimeout(15)->build();
try {
    if (isset($options['http'])) {
        $token = trim(file_get_contents($options['token-file'] ?? throw new RuntimeException('Token file is required.')));
        $transport = new HttpTransport($options['http'], ['Authorization' => 'Bearer '.$token]);
    } elseif (isset($options['docker'])) {
        $transport = new StdioTransport('docker', ['compose', 'run', '--rm', '--no-deps', '-T', 'stdio'], dirname(__DIR__));
    } else {
        $transport = new StdioTransport(PHP_BINARY, ['bin/mcp', 'mcp:server', 'default'], dirname(__DIR__));
    }
    $client->connect($transport);
    $tools = $client->listTools()->tools;
    if (count($tools) !== 10) throw new RuntimeException('Unexpected tool count.');
    $result = $client->callTool('ovh_record_add', ['zone' => 'example.com', 'type' => 'TXT', 'name' => '@', 'target' => '"demo"']);
    if ($result->isError || $result->structuredContent['status'] !== 'preview') throw new RuntimeException('Preview failed.');
    $report = ['tools' => count($tools), 'preview' => 'ok', 'protocol' => $client->getProtocolVersion()?->value];
    if (isset($options['live-zone'])) {
        $read = $client->callTool('ovh_record_list', ['zone' => $options['live-zone'], 'type' => 'MX']);
        if ($read->isError) throw new RuntimeException('Live read failed.');
        $report['live_mx_count'] = count($read->structuredContent['records']);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "MCP smoke test failed; details hidden to protect credentials.\n");
    exit(1);
} finally { $client->disconnect(); }
