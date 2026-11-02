<?php

declare(strict_types=1);

namespace OvhDns;

use Ovh\Api;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;

final class Configuration
{
    public static function loadEnvironment(string $directory): void
    {
        $file = $directory.'/.env.local';
        if (!is_file($file)) {
            return;
        }

        try {
            (new Dotenv())->load($file);
        } catch (Throwable) {
            // Dotenv's parsing errors can contain lines with credentials.
            throw new ConfigurationException('Cannot read .env.local. Check its syntax and file permissions.');
        }
    }

    /** Support Docker secret files without placing credentials in image layers. */
    public static function loadSecretFiles(): void
    {
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY', 'MCP_HTTP_TOKEN', 'APP_SECRET'] as $name) {
            $file = getenv($name.'_FILE') ?: ($_ENV[$name.'_FILE'] ?? null);
            if ($file === null) continue;
            $value = @file_get_contents($file);
            if ($value === false || trim($value) === '') throw new ConfigurationException('Cannot read secret file for '.$name.'.');
            // File secrets override dotenv values; do not copy their values into logs.
            putenv($name.'='.trim($value));
            $_ENV[$name] = trim($value);
        }
    }

    public static function createApi(?\GuzzleHttp\Client $httpClient = null): Api
    {
        $credentials = [];
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $name) {
            $value = self::value($name);
            if ($value === '') {
                throw new ConfigurationException('Missing configuration '.$name.'. Set a process variable or .env.local.');
            }
            $credentials[$name] = $value;
        }

        $endpoint = self::value('OVH_ENDPOINT', 'ovh-eu');
        if (!in_array($endpoint, ['ovh-eu', 'ovh-ca', 'ovh-us'], true)) {
            throw new ConfigurationException('OVH_ENDPOINT must be ovh-eu, ovh-ca or ovh-us.');
        }

        return new Api(
            $credentials['OVH_APPLICATION_KEY'],
            $credentials['OVH_APPLICATION_SECRET'],
            $endpoint,
            $credentials['OVH_CONSUMER_KEY'],
            $httpClient,
        );
    }

    private static function value(string $name, string $default = ''): string
    {
        $processValue = getenv($name);

        return $processValue !== false ? $processValue : (string) ($_ENV[$name] ?? $_SERVER[$name] ?? $default);
    }
}
