<?php

declare(strict_types=1);

namespace OvhDns\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use OvhDns\ConfigurationException;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class DnsApplication extends Application
{
    private ?\Closure $bootstrap = null;
    public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
    {
        parent::__construct($name, $version);
        $this->getDefinition()->addOption(new InputOption('config', null, InputOption::VALUE_REQUIRED, 'Path to a ZoneHarbor user configuration.'));
    }
    public function configureBootstrap(\Closure $bootstrap): void { $this->bootstrap = $bootstrap; }
    protected function doRunCommand(Command $command, InputInterface $input, OutputInterface $output): int
    {
        if ($this->bootstrap !== null) {
            $command->mergeApplicationDefinition();
            $input->bind($command->getDefinition());
            if (!in_array($command->getName(), ['install', 'setup', 'doctor', 'config:validate', 'mcp:config', 'help', 'list', 'completion', '_complete'], true)) {
                try { ($this->bootstrap)($input->getOption('config')); }
                catch (ConfigurationException $e) { self::error($output, $e->getMessage()); return 1; }
                catch (\InvalidArgumentException $e) { self::error($output, $e->getMessage()); return 2; }
            }
        }
        return parent::doRunCommand($command, $input, $output);
    }

    public function doRun(InputInterface $input, OutputInterface $output): int
    {
        try {
            return parent::doRun($input, $output);
        } catch (ExceptionInterface $exception) {
            self::error($output, $exception->getMessage());

            return 2;
        } catch (Throwable) {
            self::error($output, 'Unexpected application error. Details were hidden to protect configuration.');

            return 1;
        }
    }

    public static function error(OutputInterface $output, string $message): void
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $stderr->writeln('<error>'.OutputFormatter::escape($message).'</error>');
    }
}
