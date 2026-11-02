<?php

declare(strict_types=1);

namespace OvhDns\Onboarding;

/** Locked read-modify-write with private backups and an atomic replacement. */
final class PrivateFile
{
    /** @param callable(?string): string $build */
    public static function update(string $path, callable $build): ?string
    {
        if (is_link($path) || is_dir($path)) throw new \RuntimeException('Refusing to replace a directory or symlink. Select the actual file.');
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create the configuration directory.');
        $lockPath = $path.'.zone-harbor.lock';
        if (is_link($lockPath)) throw new \RuntimeException('Refusing a symlink lock file.');
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) throw new \RuntimeException('Cannot lock the configuration file.');
        $temporary = null;
        try {
            if (!chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock the configuration file.');
            clearstatcache(true, $path);
            if (is_link($path)) throw new \RuntimeException('Configuration changed to a symlink.');
            $before = is_file($path) ? @file_get_contents($path) : null;
            if ($before === false) throw new \RuntimeException('Cannot read the existing configuration.');
            $after = $build($before);
            if ($before === $after) return null;
            $backup = null;
            if ($before !== null) {
                $backup = $path.'.zone-harbor-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(4)).'.bak';
                self::create($backup, $before);
            }
            $temporary = $path.'.zone-harbor-'.bin2hex(random_bytes(8)).'.tmp';
            self::create($temporary, $after);
            if (!@rename($temporary, $path)) throw new \RuntimeException('Cannot replace the configuration file.');
            return $backup;
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
    }
    public static function create(string $file, string $contents): void
    {
        $handle = @fopen($file, 'xb');
        if ($handle === false) throw new \RuntimeException('Cannot save the private configuration file.');
        try {
            if (!chmod($file, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) throw new \RuntimeException('Cannot finish saving the private configuration file.');
        } finally { fclose($handle); }
    }
}
