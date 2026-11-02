<?php

declare(strict_types=1);

namespace OvhDns\Tui;

use OvhDns\ConfigurationException;
use OvhDns\Dns\ApiException;
use OvhDns\Dns\DnsInput;
use OvhDns\Dns\DnsService;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Operation\WritePreparationException;

/** Internal JSON protocol; never accepts PHP code or shell commands. */
final class Worker
{
    public function __construct(private readonly DnsService $dns, private readonly WriteExecutor $writes, private readonly ?\OvhDns\Health\DomainChecker $health = null) {}

    public function handle(array $request): array
    {
        $writing = ($request['action'] ?? '') === 'write';
        try {
            $action = $request['action'] ?? '';
            if ($action === 'zones') return ['status' => 'ok', 'zones' => $this->dns->listZones()];
            $zone = DnsInput::zone($request['zone'] ?? '');
            if ($action === 'health') {
                $health = $this->health ?? \OvhDns\Infrastructure\HealthFactory::create();
                return ['status' => 'ok', 'report' => json_decode(json_encode($health->domain($zone), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), true, flags: JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)];
            }
            if ($action === 'records') return ['status' => 'ok', 'records' => $this->dns->listRecords($zone)];
            if (!in_array($action, ['preview', 'write'], true)) throw new \InvalidArgumentException();
            $operation = $request['operation'] ?? '';
            $id = $request['id'] ?? null;
            $fields = $request['fields'] ?? [];
            if ($writing && in_array($operation, ['record:update', 'record:delete'], true) && !is_array($request['record'] ?? null)) {
                throw new \InvalidArgumentException();
            }
            $result = $this->writes->run($operation, $zone, $id, $fields, refresh: true,
                dryRun: !$writing, confirmDelete: $writing,
                expectedRecord: $writing ? ($request['record'] ?? null) : null);
            if (!$writing && $operation === 'record:update') $result['record'] = $this->dns->getRecord($zone, $id);
            // Present consistent outcomes without exposing transport details.
            if (isset($result['error'])) $result['error'] = self::outcomeMessage($result['status']);
            return $result;
        } catch (\InvalidArgumentException) {
            return ['status' => 'invalid_input', 'error' => 'Invalid zone, record ID or record fields. Review the form.'];
        } catch (WritePreparationException $error) {
            return ['status' => 'failed', 'error' => $error->getMessage()];
        } catch (ConfigurationException) {
            return ['status' => 'failed', 'error' => 'OVH credentials are missing or invalid. Check the local configuration.'];
        } catch (ApiException) {
            // Preview and preparation only read; writes are caught by WriteExecutor.
            return ['status' => 'failed', 'error' => 'OVH request failed. Check access and reload the zone.'];
        } catch (\Throwable) {
            return ['status' => $writing ? 'outcome_unknown' : 'failed', 'error' => $writing
                ? self::outcomeMessage('outcome_unknown') : 'Request failed. Check configuration and try loading again.'];
        }
    }

    public static function outcomeMessage(string $status): string
    {
        return match ($status) {
            'publication_accepted' => 'Saved. OVH accepted publication; public DNS propagation may take time.',
            'saved_publication_unconfirmed' => 'Saved, but publication is unconfirmed. Reload and publish the zone; do not repeat the record write.',
            'outcome_unknown' => 'Outcome unknown. Inspect records and the operation backup before retrying. No automatic retry was made.',
            default => 'OVH rejected the operation. Reload the zone before trying again.',
        };
    }
}
