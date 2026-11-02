<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Onboarding\Paths;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class InstallCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('install')->setDescription('Install a user-local zone-harbor launcher without changing shell configuration.')
            ->addOption('bin-dir', null, InputOption::VALUE_REQUIRED, 'Launcher directory; defaults to ~/.local/bin.')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'Replace an existing launcher symlink when upgrading.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $temporary = null;
        try {
            $directory = Paths::absolute($input->getOption('bin-dir') ?? Paths::home().'/.local/bin');
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) throw new \RuntimeException();
            $target = $directory.'/zone-harbor';
            if (is_link($target) && realpath($target) === realpath(Paths::launcher())) { $output->writeln('ZoneHarbor launcher is already installed.'); return 0; }
            if (is_file($target) && !is_link($target) || is_dir($target)) throw new \InvalidArgumentException('An existing file was preserved. Choose another --bin-dir.');
            if (is_link($target) && !$input->getOption('replace')) throw new \InvalidArgumentException('A launcher symlink exists. Use --replace to upgrade it.');
            $temporary = $directory.'/.zone-harbor-'.bin2hex(random_bytes(8));
            if (!@symlink(Paths::launcher(), $temporary) || !@rename($temporary, $target)) throw new \RuntimeException();
            $output->writeln('ZoneHarbor launcher installed. Run zone-harbor setup.');
            if (!in_array($directory, explode(PATH_SEPARATOR, getenv('PATH') ?: ''), true)) $output->writeln('Add the selected bin directory to PATH. No shell files were edited.');
            return 0;
        } catch (\InvalidArgumentException $e) { DnsApplication::error($output, $e->getMessage()); return 2; }
        catch (\Throwable) { DnsApplication::error($output, 'Cannot install the launcher. Select a writable --bin-dir.'); return 1; }
        finally { if ($temporary !== null && is_link($temporary)) unlink($temporary); }
    }
}
