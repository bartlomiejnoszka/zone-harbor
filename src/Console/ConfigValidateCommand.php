<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Onboarding\{Paths, UserConfiguration};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConfigValidateCommand extends Command
{
    protected function configure(): void { $this->setName('config:validate')->setDescription('Validate the selected user configuration without credentials or network access.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try { UserConfiguration::load(Paths::selected($input->getOption('config'))); $output->writeln('Configuration is valid.'); return 0; }
        catch (\OvhDns\ConfigurationException|\InvalidArgumentException $e) { DnsApplication::error($output, $e->getMessage()); return 2; }
    }
}
