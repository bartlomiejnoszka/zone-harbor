<?php

declare(strict_types=1);

namespace OvhDns\Console;

use OvhDns\Dns\DnsInput;
use OvhDns\Tui\Application;
use OvhDns\Tui\ProcessGateway;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Style\VerticalAlign;
use Symfony\Component\Tui\Tui;

final class TuiCommand extends Command implements SignalableCommandInterface
{
    private ?Application $ui = null;

    protected function configure(): void
    {
        $this->setName('dns:tui')->setDescription('Open the full-screen DNS manager (English).')
            ->addArgument('zone', InputArgument::OPTIONAL, 'Open an ASCII/punycode DNS zone or health target.')
            ->addOption('health', null, InputOption::VALUE_NONE, 'Open read-only health mode without OVH credentials.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!stream_isatty(STDIN) || !stream_isatty(STDOUT) || !$input->isInteractive()) {
            $output->writeln('The DNS manager requires an interactive terminal. Use record:list for piped output.');
            return Command::INVALID;
        }
        $zone = $input->getArgument('zone');
        try { if ($zone !== null) $zone = DnsInput::zone($zone); }
        catch (\InvalidArgumentException) { $output->writeln('Invalid zone. Use an ASCII/punycode domain name.'); return Command::INVALID; }
        $tui = new Tui(new StyleSheet([':root' => new Style(verticalAlign: VerticalAlign::Top)]));
        $this->ui = new Application($tui, new ProcessGateway());
        fwrite(STDOUT, "\x1b[?1049h\x1b[H");
        $exitCode = Command::SUCCESS;
        try {
            $this->ui->run($zone, $input->getOption('health'));
        } catch (\Throwable) {
            // Restore first, then report a safe English error outside the TUI.
            $tui->stop();
            $exitCode = Command::FAILURE;
        } finally {
            fwrite(STDOUT, "\x1b[?1049l");
            $this->ui = null;
        }
        if ($exitCode !== Command::SUCCESS) $output->writeln('The terminal interface stopped unexpectedly. Inspect zone state before repeating any interrupted write.');
        return $exitCode;
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->ui?->quit();
        return false;
    }
}
