<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

use GuzzleHttp\Client as HttpClient;
use Mcp\Client;
use Mcp\Client\Transport\StdioTransport;
use OvhDns\Configuration;
use OvhDns\Dns\OvhDnsClient;

final class Diagnostics
{
    public function inspect(string $file, bool $online = false): array
    {
        $checks = [];
        $add = static function (string $id, string $status, string $message) use (&$checks): void { $checks[] = compact('id', 'status', 'message'); };
        $add('php', PHP_VERSION_ID >= 80401 ? 'PASS' : 'FAIL', 'PHP '.PHP_VERSION.'; minimum 8.4.1.');
        foreach (['json', 'intl', 'mbstring', 'openssl'] as $extension) $add('extension.'.$extension, extension_loaded($extension) ? 'PASS' : 'FAIL', 'Required PHP extension: '.$extension.'.');
        $add('tui', extension_loaded('pcntl') && PHP_OS_FAMILY !== 'Windows' ? 'PASS' : 'WARN', 'Interactive TUI requires a Unix terminal and pcntl. CLI/MCP can run without a TUI.');
        try {
            $user = UserConfiguration::load($file);
            $add('configuration', 'PASS', 'Version 1 configuration is valid; mode: '.$user->mode().'.');
            Configuration::initialize(dirname(__DIR__, 2), $file);
            $directory = $user->stateDirectory();
            while (!file_exists($directory) && dirname($directory) !== $directory) $directory = dirname($directory);
            $writable = is_dir($directory) && is_writable($directory);
            $add('storage', $writable ? 'PASS' : 'FAIL', $writable ? 'Runtime cache and backup directory can be created or written.' : 'Runtime storage needs a writable directory; a file or permission may block the selected path.');
            if (($credentials = $user->credentialsFile()) !== null && $user->mode() === 'ovh') {
                $permissions = @fileperms($credentials);
                $add('credentials.permissions', $permissions !== false && ($permissions & 0077) === 0 ? 'PASS' : 'WARN', 'Credentials should be readable only by their owner (0600).');
            }
            $add('ovh.credentials', $user->mode() === 'health' ? 'SKIP' : (Configuration::hasCredentials() ? 'PASS' : 'FAIL'), $user->mode() === 'health' ? 'Public health mode does not use OVH credentials.' : 'OVH credentials are configured; values are hidden.');
            if ($online && $user->mode() === 'ovh' && Configuration::hasCredentials()) {
                try {
                    (new OvhDnsClient(Configuration::createApi(new HttpClient(['timeout' => 5, 'connect_timeout' => 2]))))->listZones();
                    $add('ovh.access', 'PASS', 'OVH zone listing succeeded using a read-only request.');
                } catch (\Throwable) { $add('ovh.access', 'FAIL', 'OVH access could not be verified. Check credentials, permissions and network access.'); }
            }
            if ($online) {
                $client = Client::builder()->setClientInfo('zone-harbor-doctor', \OvhDns\Version::NUMBER)->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
                try {
                    $client->connect(new StdioTransport(PHP_BINARY, [Paths::launcher(), '--config', $file, 'mcp:serve'], dirname(__DIR__, 2)));
                    $names = array_map(static fn ($tool): string => $tool->name, $client->listTools()->tools);
                    if (array_diff(['dns_domain_check', 'tls_certificate_check'], $names) !== []) throw new \RuntimeException();
                    $add('mcp', 'PASS', 'Local STDIO handshake and tool discovery succeeded.');
                } catch (\Throwable) { $add('mcp', 'FAIL', 'Local MCP startup failed. Check configuration and state directory permissions.'); }
                finally { $client->disconnect(); }
            } else $add('connections', 'SKIP', 'Use doctor --online to test local MCP and read-only OVH access.');
        } catch (\Throwable) { $add('configuration', 'FAIL', 'Cannot load configuration or its credential files. Run setup or config:validate. Details were hidden.'); }
        $status = 'PASS';
        foreach ($checks as $check) { if ($check['status'] === 'FAIL') { $status = 'FAIL'; break; } if ($check['status'] === 'WARN') $status = 'WARN'; }
        return ['status' => $status, 'checks' => $checks];
    }
}
