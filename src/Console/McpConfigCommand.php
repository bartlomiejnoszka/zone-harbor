<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Onboarding\{McpClientConfiguration, Paths, PrivateFile, UserConfiguration};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class McpConfigCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('mcp:config')->setDescription('Generate local MCP client settings; preview by default.')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'generic, claude-desktop or cursor.', 'generic')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Client JSON file to preview or update.')
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'native or docker; inferred from the selected configuration.')
            ->addOption('docker-command', null, InputOption::VALUE_REQUIRED, 'Host Docker executable for generated client settings.', 'docker')
            ->addOption('docker-image', null, InputOption::VALUE_REQUIRED, 'Docker image used by the local MCP client.')
            ->addOption('docker-volume', null, InputOption::VALUE_REQUIRED, 'Named Docker volume containing the user configuration.')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Merge the ZoneHarbor entry into the selected client file with a private backup.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $config = Paths::selected($input->getOption('config'));
            UserConfiguration::load($config);
            $client = $input->getOption('client');
            if (!in_array($client, ['generic', 'cursor', 'claude-desktop'], true)) throw new \InvalidArgumentException('Choose generic, claude-desktop or cursor.');
            $file = $input->getOption('output');
            $file = $file !== null ? Paths::absolute($file) : ($client === 'generic' ? null : Paths::clientConfig($client));
            $generator = new McpClientConfiguration($input->getOption('runtime') ?? McpClientConfiguration::runtimeFor($config), $input->getOption('docker-command'), $input->getOption('docker-image') ?? (getenv('ZONE_HARBOR_DOCKER_IMAGE') ?: 'zone-harbor:local'), $input->getOption('docker-volume') ?? (getenv('ZONE_HARBOR_DOCKER_VOLUME') ?: 'zone-harbor_config'), client: $client);
            if ($input->getOption('write')) {
                if (McpClientConfiguration::inContainer() && $input->getOption('output') === null) throw new \InvalidArgumentException('Use --output with a host-mounted client file, or copy the generated JSON to your host client.');
                if ($file === null) throw new \InvalidArgumentException('Select --output when writing generic MCP settings.');
                $backup = PrivateFile::update($file, fn (?string $before): string => $generator->merge($before, $config));
                $output->writeln('MCP client settings saved. Other server entries were preserved.'.($backup === null ? '' : ' A private backup was saved beside the file.'));
                return 0;
            }
            // Print only our generated entry: other client settings may contain unrelated secrets.
            $output->write($generator->merge(null, $config), false, OutputInterface::OUTPUT_RAW);
            return 0;
        } catch (\InvalidArgumentException|\OvhDns\ConfigurationException $e) { DnsApplication::error($output, $e->getMessage()); return 2; }
        catch (\Throwable) { DnsApplication::error($output, 'Cannot update MCP client configuration. Check file permissions; no secret values were printed.'); return 1; }
    }
}
