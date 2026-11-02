<?php

declare(strict_types=1);

use OvhDns\Mcp\DnsTools;
use OvhDns\Mcp\HealthTools;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    // The bundle's allowlist nodes require literal arrays at container compilation.
    // Rebuild the container cache after changing these deployment settings.
    $csv = static function (string $name, string $default): array {
        $value = getenv($name);
        return array_values(array_filter(array_map('trim', explode(',', $value !== false ? $value : ($_ENV[$name] ?? $default)))));
    };
    $hosts = $csv('MCP_ALLOWED_HOSTS', 'localhost,127.0.0.1');
    if ($hosts === [] || in_array('*', $hosts, true)) throw new \LogicException('MCP_ALLOWED_HOSTS requires explicit hostnames.');
    $origins = $csv('MCP_ALLOWED_ORIGINS', '');
    if (in_array('*', $origins, true)) throw new \LogicException('MCP_ALLOWED_ORIGINS must not contain a wildcard.');
    $container->extension('mcp', ['servers' => ['default' => [
        'name' => 'zone-harbor',
        'version' => '0.1.0',
        'instructions' => 'Inspect public domain health and TLS certificates without provider credentials. Manage existing OVH DNS zones. Preview mutations first. Refresh publishes ALL pending changes, including external edits. Never retry unknown writes automatically. OVH acceptance is not DNS propagation. Caller flags are not human approval.',
        'transports' => ['stdio' => true, 'http' => true],
        'http' => ['path' => '/mcp', 'allowed_hosts' => $hosts, 'allowed_origins' => $origins],
        'session' => ['store' => 'file', 'lock' => ['enabled' => true]],
        'registry' => ['tools' => [DnsTools::class, HealthTools::class]],
    ]]]);
};
