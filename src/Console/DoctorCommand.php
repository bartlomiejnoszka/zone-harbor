<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Onboarding\{Diagnostics, Paths};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('doctor')->setDescription('Diagnose configuration, PHP and runtime paths without DNS writes.')
            ->addOption('online', null, InputOption::VALUE_NONE, 'Also test local MCP discovery and read-only OVH access.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return diagnostic JSON without credential values.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { $report = (new Diagnostics())->inspect(Paths::selected($input->getOption('config')), $input->getOption('online')); }
        catch (\Throwable) { $report = ['status' => 'FAIL', 'checks' => [['id' => 'configuration', 'status' => 'FAIL', 'message' => 'Cannot select a configuration. Use --config with an absolute local path.']]]; }
        if ($input->getOption('json')) $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        else {
            (new Table($output))->setHeaders(['Status', 'Check', 'Result'])->setRows(array_map(static fn (array $row): array => [$row['status'], $row['id'], $row['message']], $report['checks']))->render();
        }
        return $report['status'] === 'FAIL' ? 1 : 0;
    }
}
