<?php

declare(strict_types=1);

namespace OvhDns\Operation;

use OvhDns\Dns\DnsInput;
use OvhDns\Dns\DnsService;
use OvhDns\Provider\ProviderException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** Coordinates a complete DNS write: lock, backup, mutation, optional publication. */
final class WriteExecutor
{
    public function __construct(private readonly DnsService $dns, private readonly string $directory) {}

    public function run(string $operation, string $zone, ?string $id = null, array $fields = [], bool $refresh = true, bool $dryRun = true, bool $confirmDelete = false, ?array $expectedRecord = null): array
    {
        $zone = DnsInput::zone($zone);
        if (!$dryRun && $operation === 'record:delete' && !$confirmDelete) {
            throw new \InvalidArgumentException('Deletion requires confirmDelete=true. Preview the exact record first.');
        }
        if ($dryRun) {
            return $this->dns->plan($operation, $zone, $id, $fields, $refresh) + ['status' => 'preview', 'dryRun' => true];
        }
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new WritePreparationException('Cannot create backup directory; no DNS write attempted.');
        }
        $lock = (new LockFactory(new FlockStore($this->directory)))->createLock($this->dns->provider()->key().':'.$zone);
        if (!$lock->acquire()) {
            throw new WritePreparationException('Another write to this zone is running. No DNS write attempted.');
        }
        try {
            $plan = $this->dns->plan($operation, $zone, $id, $fields, $refresh);
            if ($expectedRecord !== null) {
                $current = $plan['record'] ?? $this->dns->getRecord($zone, $this->dns->reference($id ?? ''));
                if (self::recordSnapshot($current) !== self::recordSnapshot($expectedRecord)) {
                    throw new WritePreparationException('The record changed since preview. Reload and preview again; no DNS write attempted.');
                }
            }
            $backup = $this->directory.'/'.gmdate('Ymd\THis\Z').'-'.$zone.'-'.bin2hex(random_bytes(8));
            if (!@mkdir($backup, 0700)) throw new WritePreparationException('Cannot create backup; no DNS write attempted.');
            if ($this->dns->provider()->capabilities()->nativeExport) {
                $native = $this->dns->exportZone($zone);
                $this->save($backup.'/before.zone', $native);
            }
            $this->save($backup.'/before.json', json_encode($this->dns->provider()->records($zone), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $this->save($backup.'/plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            // Durable intent differentiates an interrupted/unknown write from a preview.
            $this->save($backup.'/intent.json', json_encode(['status' => 'write_intent', 'operation' => $operation, 'zone' => $zone], JSON_THROW_ON_ERROR));
            try {
                $result = $this->dns->executePlan($plan);
            } catch (\Throwable $error) {
                $message = $error instanceof ProviderException ? $error->getMessage() : 'Operation could not be confirmed. Inspect the zone before retrying.';
                $result = ['operation' => $operation, 'zone' => $zone, 'id' => $id,
                    'status' => !$error instanceof ProviderException || $error->outcomeUnknown ? 'outcome_unknown' : 'failed',
                    'error' => $message];
            }
            try {
                $this->save($backup.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (WritePreparationException) {
                $result['warning'] = 'Operation completed but its local result could not be saved. Do not repeat the write.';
            }
            return $result + ['backup' => $backup, 'dryRun' => false];
        } finally {
            $lock->release();
        }
    }

    /** Compare only the immutable ID and DNS fields, independent of API key order. */
    private static function recordSnapshot(array $record): array
    {
        return [(string) ($record['id'] ?? ''), $record['fieldType'] ?? '', $record['subDomain'] ?? '',
            (string) ($record['ttl'] ?? 0), $record['target'] ?? ''];
    }

    private function save(string $file, string $contents): void
    {
        $handle = @fopen($file, 'xb');
        if ($handle === false) throw new WritePreparationException('Cannot save backup; inspect state before retrying.');
        try {
            if (!chmod($file, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle) || !fsync($handle)) {
                throw new WritePreparationException('Cannot save backup; inspect state before retrying.');
            }
        } finally {
            fclose($handle);
        }
    }
}
