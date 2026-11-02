<?php

declare(strict_types=1);

namespace OvhDns\Console;

use Closure;
use OvhDns\Health\DomainChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class HealthCommand extends Command
{
    /** @param Closure(): DomainChecker $factory */
    public function __construct(string $name, private readonly Closure $factory) { parent::__construct($name); }
    protected function configure(): void
    {
        $certificate = $this->getName() === 'certificate:check';
        $this->setDescription($certificate ? 'Inspect a public TLS certificate without authentication.' : 'Audit public DNS, mail records and configured TLS endpoints without DNS writes.')
            ->addArgument($certificate ? 'host' : 'domain', InputArgument::REQUIRED, 'ASCII/punycode hostname.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return a structured health report.');
        if ($certificate) $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'TLS: 443, 465, 993; SMTP STARTTLS: 587.', '443');
        $this->setHelp('Read-only. No OVH credentials required. Exit codes: 0 no failures/incomplete checks (warnings allowed), 1 confirmed failures, 2 invalid input/configuration, 3 incomplete checks. Mail authentication requires a delivered-message test.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $checker = ($this->factory)();
            if ($this->getName() === 'certificate:check') {
                $port = $input->getOption('port');
                if (!is_string($port) || !ctype_digit($port)) throw new \InvalidArgumentException('Port must be an integer.');
                $report = $checker->certificate($input->getArgument('host'), (int) $port);
            } else $report = $checker->domain($input->getArgument('domain'));
        } catch (\InvalidArgumentException $error) { DnsApplication::error($output, $error->getMessage()); return 2; }
        catch (\Throwable) { DnsApplication::error($output, 'Health inspection could not complete. Check configuration and retry.'); return 3; }
        if ($input->getOption('json')) $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), OutputInterface::OUTPUT_RAW);
        else {
            $output->writeln(OutputFormatter::escape($report->target.' — '.$report->status()->value.' — '.$report->observedAt->format('Y-m-d H:i:s').' UTC'));
            $rows = [];
            foreach ($report->checks as $check) $rows[] = array_map(OutputFormatter::escape(...), [$check->status->value, $check->id, $check->summary]);
            (new Table($output))->setHeaders(['Status', 'Check', 'Finding'])->setRows($rows)->render();
            $output->writeln('Use --json for evidence, sources and remediation. Read-only; no DNS changes were made.');
        }
        return $report->exitCode();
    }
}
