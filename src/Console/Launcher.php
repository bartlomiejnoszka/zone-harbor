<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Configuration;
use OvhDns\Onboarding\Paths;
use Symfony\Component\Console\Input\ArgvInput;

final class Launcher
{
    public static function run(array $arguments, bool $tuiDefault = true): int
    {
        umask(0077);
        $root = dirname(__DIR__, 2);
        $app = ApplicationFactory::create();
        $app->setAutoExit(false);
        $app->configureBootstrap(static fn (?string $file) => Configuration::initialize($root, $file));
        if ($tuiDefault && stream_isatty(STDIN) && stream_isatty(STDOUT)) {
            $firstRun = count($arguments) === 1 && !is_file(Paths::selected()) && !is_file($root.'/.env.local') && !Configuration::hasCredentials();
            $app->setDefaultCommand($firstRun ? 'setup' : 'dns:tui');
        }
        return $app->run(new ArgvInput($arguments));
    }
}
