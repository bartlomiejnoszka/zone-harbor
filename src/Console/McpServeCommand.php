<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{ArgvInput, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;

final class McpServeCommand extends Command
{
    protected function configure(): void { $this->setName('mcp:serve')->setDescription('Serve MCP over STDIO without interactive prompts or startup banners.'); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = new Application(new Kernel('prod', false));
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        return $application->run(new ArgvInput(['zone-harbor', 'mcp:server', 'default', '--no-interaction']), $output);
    }
}
