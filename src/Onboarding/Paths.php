<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

final class Paths
{
    public static function home(): string
    {
        $home = getenv('HOME');
        if ($home === false || $home === '' || !str_starts_with($home, '/')) throw new \InvalidArgumentException('Set an absolute HOME or select a configuration with --config.');
        return rtrim($home, '/');
    }
    public static function defaultConfig(): string
    {
        $xdg = getenv('XDG_CONFIG_HOME');
        if (is_string($xdg) && str_starts_with($xdg, '/')) return rtrim($xdg, '/').'/zone-harbor/config.yaml';
        return self::home().(PHP_OS_FAMILY === 'Darwin' ? '/Library/Application Support/ZoneHarbor/config.yaml' : '/.config/zone-harbor/config.yaml');
    }
    public static function selected(?string $explicit = null): string
    {
        $file = $explicit ?? (getenv('ZONE_HARBOR_CONFIG') ?: null) ?? self::defaultConfig();
        return self::absolute($file);
    }
    public static function absolute(string $file, ?string $base = null): string
    {
        if ($file === '' || str_contains($file, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $file)) throw new \InvalidArgumentException('Use a local configuration path.');
        if (str_starts_with($file, '~/')) $file = self::home().substr($file, 1);
        $path = str_starts_with($file, '/') ? $file : ($base ?? getcwd()).'/'.$file;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') array_pop($parts); else $parts[] = $part;
        }
        return '/'.implode('/', $parts);
    }
    public static function php(): string
    {
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $candidate = rtrim($directory, '/').'/php';
            if (str_starts_with($directory, '/') && is_executable($candidate) && realpath($candidate) === realpath(PHP_BINARY)) return $candidate;
        }
        return PHP_BINARY;
    }
    public static function launcher(): string
    {
        $source = dirname(__DIR__, 2).'/bin/zone-harbor';
        $invoked = $_SERVER['argv'][0] ?? '';
        if (is_string($invoked) && str_starts_with($invoked, '/') && realpath($invoked) === realpath($source)) return $invoked;
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            $candidate = rtrim($directory, '/').'/zone-harbor';
            if (str_starts_with($directory, '/') && is_executable($candidate) && realpath($candidate) === realpath($source)) return $candidate;
        }
        return $source;
    }
    public static function clientConfig(string $client): string
    {
        return match ($client) {
            'cursor' => self::home().'/.cursor/mcp.json',
            'claude-desktop' => PHP_OS_FAMILY === 'Darwin' ? self::home().'/Library/Application Support/Claude/claude_desktop_config.json' : (getenv('XDG_CONFIG_HOME') ?: self::home().'/.config').'/Claude/claude_desktop_config.json',
            default => throw new \InvalidArgumentException('Generic MCP configuration needs an explicit --output path.'),
        };
    }
}
