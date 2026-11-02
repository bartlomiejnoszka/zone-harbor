<?php

declare(strict_types=1);

use OvhDns\Version;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';
$archive = dirname(__DIR__).'/dist/zone-harbor-'.Version::NUMBER.'.tar.gz';
$directory = sys_get_temp_dir().'/zone-harbor-release-'.bin2hex(random_bytes(8));
mkdir($directory, 0700);
try {
    $checksum = explode(' ', trim(file_get_contents($archive.'.sha256')))[0];
    if (!hash_equals($checksum, hash_file('sha256', $archive))) throw new RuntimeException('Archive checksum mismatch.');
    $tar = new PharData($archive);
    foreach (new RecursiveIteratorIterator($tar) as $file) {
        $path = substr($file->getPathname(), strlen('phar://'.$archive.'/'));
        foreach (['.git/', 'var/', 'operations/', 'secrets/', 'vendor/phpunit/', 'vendor/phpstan/phpstan/'] as $prefix) if (str_starts_with($path, $prefix)) throw new RuntimeException('Private or development files in release.');
        if (in_array(basename($path), ['.env.local', '.env.docker.local', 'zone-harbor.local.yaml'], true)) throw new RuntimeException('Private configuration in release.');
    }
    $tar->extractTo($directory.'/app');
    $launcher = $directory.'/app/bin/zone-harbor';
    $config = $directory.'/settings/config.yaml';
    $environment = ['ZONE_HARBOR_CONFIG' => false];
    foreach (['OVH_APPLICATION_KEY', 'OVH_APPLICATION_SECRET', 'OVH_CONSUMER_KEY', 'OVH_ENDPOINT', 'MCP_HTTP_TOKEN', 'APP_SECRET'] as $key) { $environment[$key] = false; $environment[$key.'_FILE'] = false; }
    $run = static function (array $arguments) use ($launcher, $directory, $environment): Process {
        $process = new Process([PHP_BINARY, $launcher, ...$arguments], $directory, $environment);
        $process->setTimeout(25); $process->mustRun(); return $process;
    };
    $run(['setup','--config',$config,'--mode=health','--domain=example.com','--skip-checks','-n']);
    $run(['config:validate','--config',$config]);
    $report = json_decode($run(['doctor','--config',$config,'--online','--json'])->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    if ($report['status'] === 'FAIL') throw new RuntimeException('Packaged MCP diagnostic failed.');
    $run(['install','--bin-dir',$directory.'/bin']);
    (new Process([$directory.'/bin/zone-harbor', '--version'], $directory, $environment))->mustRun();
    echo "Release bundle verified: checksum, public files, isolated setup, local MCP and launcher installation.\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    rmdir($directory);
}
