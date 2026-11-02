<?php

declare(strict_types=1);

namespace OvhDns;

use Ovh\Api;
use Symfony\Component\Dotenv\Dotenv;
use Throwable;
use OvhDns\Onboarding\{Paths, UserConfiguration};

final class Configuration
{
    private static ?UserConfiguration $user = null;

    public static function user(): ?UserConfiguration { return self::$user; }
    public static function reset(): void { self::$user = null; }
    public static function initialize(string $projectDirectory, ?string $selected = null): void
    {
        self::$user = null;
        $explicit = $selected ?? (getenv('ZONE_HARBOR_CONFIG') ?: null);
        $file = Paths::selected($selected);
        if ($explicit !== null || is_file($file)) {
            self::$user = UserConfiguration::load($file);
            putenv('ZONE_HARBOR_CONFIG='.self::$user->file);
            $_ENV['ZONE_HARBOR_CONFIG'] = self::$user->file;
            // User settings replace checkout-local defaults. Real process variables remain authoritative.
            if (self::$user->mode() === 'ovh' && ($credentials = self::$user->credentialsFile()) !== null) self::loadDotenv($credentials);
        } else self::loadEnvironment($projectDirectory);
        self::loadSecretFiles();
    }
    public static function operationDirectory(): string
    {
        return self::$user !== null ? self::$user->stateDirectory().'/operations' : dirname(__DIR__).'/operations/mcp';
    }
    public static function providerEnabled(): bool { return self::$user === null || self::$user->mode() === 'ovh'; }
    public static function hasCredentials(): bool
    {
        if (!self::providerEnabled()) return false;
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $name) if (self::value($name) === '') return false;
        return true;
    }
    private static function loadDotenv(string $file): void
    {
        if (!is_file($file)) throw new ConfigurationException('Cannot read the configured credentials file. Run setup to repair account settings.');
        try { (new Dotenv())->load($file); }
        catch (Throwable) { throw new ConfigurationException('Cannot read the configured credentials file. Check its syntax and permissions.'); }
    }

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
            if (!self::providerEnabled() && str_starts_with($name, 'OVH_')) continue;
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
        if (!self::providerEnabled()) throw new ConfigurationException('OVH management is disabled in this configuration. Run zone-harbor setup to enable it.');
        $credentials = [];
        foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY'] as $name) {
            $value = self::value($name);
            if ($value === '') {
                throw new ConfigurationException('Missing configuration '.$name.'. Set a process variable or .env.local.');
            }
            $credentials[$name] = $value;
        }

        $endpoint = self::value('OVH_ENDPOINT', self::$user?->data['ovh']['endpoint'] ?? 'ovh-eu');
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
