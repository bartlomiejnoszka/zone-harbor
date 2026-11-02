<?php

declare(strict_types=1);

/** Build a dependency-complete, versioned PHP bundle from an explicit public-file allowlist. */
require dirname(__DIR__).'/vendor/autoload.php';

use OvhDns\Version;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
$output = $root.'/dist';
if (!is_dir($output)) mkdir($output, 0755);
$stage = $output.'/.build-'.bin2hex(random_bytes(8));
mkdir($stage, 0700);
$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_link($from)) throw new RuntimeException('Release sources must not contain symlinks.');
    if (is_dir($from)) {
        if (!is_dir($to)) mkdir($to, 0755, true);
        foreach (new DirectoryIterator($from) as $item) if (!$item->isDot()) $copy($item->getPathname(), $to.'/'.$item->getFilename());
    } else {
        if (!copy($from, $to)) throw new RuntimeException('Cannot copy a release source.');
        chmod($to, fileperms($from) & 0777);
    }
};
try {
    foreach (['bin', 'src', 'config', 'schema', 'examples', 'docs', 'public', 'bootstrap.php', 'composer.json', 'composer.lock', 'LICENSE', 'README.md', 'SECURITY.md', 'CHANGELOG.md', '.env.example', 'zone-harbor.example.yaml'] as $path) $copy($root.'/'.$path, $stage.'/'.$path);
    $install = new Process(['composer', 'install', '--no-dev', '--no-scripts', '--no-interaction', '--no-progress', '--prefer-dist'], $stage);
    $install->setTimeout(180);
    $install->mustRun();
    $archive = $output.'/zone-harbor-'.Version::NUMBER.'.tar';
    foreach ([$archive, $archive.'.gz', $archive.'.gz.sha256'] as $file) if (is_file($file)) unlink($file);
    $tar = new PharData($archive);
    $tar->buildFromDirectory($stage);
    $tar->compress(Phar::GZ); unset($tar); unlink($archive);
    file_put_contents($archive.'.gz.sha256', hash_file('sha256', $archive.'.gz').'  '.basename($archive).'.gz'."\n");
    echo basename($archive).'.gz and SHA-256 checksum built in dist/.'.PHP_EOL;
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($stage);
}
