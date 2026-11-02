<?php

declare(strict_types=1);

namespace OvhDns\Console;

use Closure;
use OvhDns\ConfigurationException;
use OvhDns\Provider\ProviderException;
use OvhDns\Dns\DnsService;
use OvhDns\Dns\DnsInput;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Operation\WritePreparationException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

final class DnsCommand extends Command
{
    /** @param Closure(): DnsService $clientProvider */
    public function __construct(string $name, private readonly Closure $clientProvider, private readonly WriteExecutor $writes)
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $descriptions = [
            'zone:list' => 'List DNS zones accessible in OVH.',
            'zone:export' => 'Write the DNS zone file to stdout.',
            'zone:refresh' => 'Publish all pending zone changes.',
            'record:list' => 'List full DNS records in a zone.',
            'record:show' => 'Show a DNS record by ID.',
            'record:add' => 'Add a DNS record and publish changes.',
            'record:update' => 'Update supplied DNS record fields and publish changes.',
            'record:delete' => 'Delete a DNS record by ID and publish changes.',
        ];
        $name = $this->getName();
        $this->setDescription($descriptions[$name]);

        if ($name !== 'zone:list') {
            $this->addArgument('zone', InputArgument::REQUIRED, 'Zone name, e.g. example.com (use punycode for IDNs).');
        }
        if (in_array($name, ['record:show', 'record:update', 'record:delete'], true)) {
            $this->addArgument('id', InputArgument::REQUIRED, 'Positive record ID returned by OVH.');
        }
        if (in_array($name, ['zone:list', 'record:list', 'record:show'], true)) {
            $this->addOption('json', null, InputOption::VALUE_NONE, 'Return JSON for scripts.');
        }
        if (in_array($name, ['record:list', 'record:add'], true)) {
            $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Record type, e.g. A, AAAA, TXT or MX.');
        }
        if (in_array($name, ['record:list', 'record:add', 'record:update'], true)) {
            $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Relative name, e.g. www, _dmarc or @ for the apex.');
        }
        if (in_array($name, ['record:add', 'record:update'], true)) {
            $this->addOption('target', null, InputOption::VALUE_REQUIRED, 'OVH zone-file value, e.g. an IP address or quoted TXT text.')
                ->addOption('ttl', null, InputOption::VALUE_REQUIRED, 'TTL in seconds; 0 = zone default.', $name === 'record:add' ? '0' : null);
        }
        if (in_array($name, ['record:add', 'record:update', 'record:delete'], true)) {
            $this->addOption('no-refresh', null, InputOption::VALUE_NONE, 'Save without publishing the zone.')
                ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without saving or publishing changes.');
        }
        if ($name === 'record:delete') {
            $this->addOption('force', null, InputOption::VALUE_NONE, 'Delete without a confirmation prompt (required with -n).');
        }
        if ($name === 'zone:refresh') {
            $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview without publishing changes.');
        }
        if (in_array($name, ['zone:refresh', 'record:add', 'record:update', 'record:delete'], true)) {
            $this->setHelp('Publication includes ALL pending zone changes, including external edits. API acceptance does not prove DNS propagation. Real writes require zone export and record read permissions for backups.');
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->executeOperation($input, $output);
        } catch (InvalidArgumentException $exception) {
            DnsApplication::error($output, $exception->getMessage());

            return self::INVALID;
        } catch (ProviderException|ConfigurationException|WritePreparationException $exception) {
            DnsApplication::error($output, $exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            DnsApplication::error($output, 'Unexpected error. Inspect zone state before retrying a write. Details were hidden to protect configuration.');

            return self::FAILURE;
        }
    }

    private function executeOperation(InputInterface $input, OutputInterface $output): int
    {
        $name = $this->getName();
        $dns = ($this->clientProvider)();
        if ($name === 'zone:list') {
            $zones = $dns->listZones();
            if ($input->getOption('json')) $this->json($output, $zones);
            else (new Table($output))->setHeaders(['Zone'])->setRows(array_map(static fn (string $zone): array => [OutputFormatter::escape($zone)], $zones))->render();
            return self::SUCCESS;
        }
        $zone = $this->zone($input->getArgument('zone'));
        if ($name === 'zone:export') { $output->write($dns->exportZone($zone), false, OutputInterface::OUTPUT_RAW); return self::SUCCESS; }
        if ($name === 'record:list') {
            $this->records($output, $dns->listRecords($zone, $this->type($input->getOption('type')), $this->relativeName($input->getOption('name'))), $input->getOption('json'));
            return self::SUCCESS;
        }
        $id = in_array($name, ['record:show', 'record:update', 'record:delete'], true) ? $dns->reference($input->getArgument('id')) : null;
        if ($name === 'record:show') {
            $record = $dns->getRecord($zone, $id);
            $input->getOption('json') ? $this->json($output, $record) : $this->records($output, [$record], false);
            return self::SUCCESS;
        }
        $dry = $input->getOption('dry-run');
        $refresh = $name === 'zone:refresh' || !$input->getOption('no-refresh');
        $fields = in_array($name, ['record:add', 'record:update'], true) ? $this->fields($input, $name === 'record:add') : [];
        $expected = null;
        if ($name === 'record:delete' && !$dry) {
            if (!$input->getOption('force') && !$input->isInteractive()) throw new InvalidArgumentException('Non-interactive deletion requires --force. Use --dry-run to preview.');
            $expected = $dns->getRecord($zone, $id);
            if (!$input->getOption('force')) {
                $this->records($output, [$expected], false);
                if (!(new SymfonyStyle($input, $output))->confirm('Delete this record from '.$zone.'?', false)) { $output->writeln('Cancelled.'); return self::SUCCESS; }
            }
        }
        $result = $this->writes->run($name, $zone, $id, $fields, $refresh, $dry, $name === 'record:delete', $expected);
        if ($dry) { $this->dryRun($output, $result); return self::SUCCESS; }
        if (!in_array($result['status'], ['failed', 'outcome_unknown'], true) && $name !== 'zone:refresh') {
            $output->writeln(($name === 'record:delete' ? 'Deleted' : 'Saved').' record #'.($result['id'] ?? '').'.');
        }
        if (isset($result['error'])) { DnsApplication::error($output, $result['error']); return self::FAILURE; }
        $output->writeln($result['published'] ? 'OVH accepted publication of all pending changes for '.$zone.'.' : 'Change pending publication: php bin/console zone:refresh '.$zone);
        if (isset($result['warning'])) $output->writeln(OutputFormatter::escape($result['warning']));
        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function fields(InputInterface $input, bool $creating): array
    {
        $fields = [];
        if ($creating) {
            $fields['fieldType'] = $this->type($this->requiredOption($input, 'type'));
            $fields['subDomain'] = $this->relativeName($this->requiredOption($input, 'name'));
            $fields['target'] = $this->requiredOption($input, 'target');
        } else {
            if ($input->getOption('name') !== null) {
                $fields['subDomain'] = $this->relativeName($input->getOption('name'));
            }
            if ($input->getOption('target') !== null) {
                $fields['target'] = $this->requiredOption($input, 'target');
            }
        }
        if ($input->getOption('ttl') !== null) {
            $ttl = $input->getOption('ttl');
            $value = filter_var($ttl, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
            if (!ctype_digit($ttl) || $value === false) {
                throw new InvalidArgumentException('TTL must be an integer from 0 to 2147483647.');
            }
            $fields['ttl'] = $value;
        }
        if ($fields === []) {
            throw new InvalidArgumentException('Supply at least one of --name, --target or --ttl. Record type cannot change.');
        }

        return $fields;
    }

    private function requiredOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException('A non-empty option is required: --'.$name.'.');
        }

        return $value;
    }

    private function type(?string $value): ?string { return DnsInput::type($value); }
    private function relativeName(?string $name): ?string { return DnsInput::relativeName($name); }
    private function zone(string $value): string { return DnsInput::zone($value); }

    private function json(OutputInterface $output, mixed $data): void
    {
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }

    private function dryRun(OutputInterface $output, array $operation): void
    {
        $output->writeln('DRY RUN: no changes saved or published.');
        $this->json($output, $operation);
    }

    /** @param list<array<string, mixed>> $records */
    private function records(OutputInterface $output, array $records, bool $json): void
    {
        if ($json) {
            $this->json($output, $records);

            return;
        }
        $rows = [];
        foreach ($records as $record) {
            $values = [$record['id'], $record['zone'], $record['fieldType'], ($record['subDomain'] ?? '') === '' ? '@' : $record['subDomain'], $record['ttl'] ?? 'default', $record['target']];
            $rows[] = array_map(static fn (mixed $value): string => OutputFormatter::escape((string) $value), $values);
        }
        (new Table($output))->setHeaders(['ID', 'Zone', 'Type', 'Name', 'TTL', 'Target'])->setRows($rows)->render();
    }
}
