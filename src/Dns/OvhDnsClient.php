<?php

declare(strict_types=1);

namespace OvhDns\Dns;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Ovh\Api;

final class OvhDnsClient
{
    public function __construct(private readonly Api $api, private readonly ?\Closure $beforeRequest = null)
    {
    }

    /** @return list<string> */
    public function listZones(): array
    {
        $zones = $this->request('GET', '/domain/zone');
        sort($zones, SORT_STRING);

        return $zones;
    }

    public function exportZone(string $zone): string
    {
        return $this->request('GET', $this->zonePath($zone).'/export');
    }

    /** @return list<array<string, mixed>> */
    public function listRecords(string $zone, ?string $type = null, ?string $name = null): array
    {
        $filters = [];
        if ($type !== null) {
            $filters['fieldType'] = $type;
        }
        if ($name !== null) {
            $filters['subDomain'] = $name;
        }
        $ids = $this->request('GET', $this->zonePath($zone).'/record', $filters);
        sort($ids, SORT_NUMERIC);
        $records = [];
        foreach ($ids as $id) {
            $record = $this->getRecord($zone, (string) $id);
            // OVH documents these API filters as LIKE/ILIKE. Keep CLI matches exact,
            // including the root (empty string) and literal wildcard records.
            if ($type !== null && $record['fieldType'] !== $type) {
                continue;
            }
            if ($name !== null && strcasecmp($record['subDomain'] ?? '', $name) !== 0) {
                continue;
            }
            $records[] = $record;
        }

        return $records;
    }

    /** @return array<string, mixed> */
    public function getRecord(string $zone, string $id): array
    {
        return $this->request('GET', $this->recordPath($zone, $id));
    }

    /** @param array<string, mixed> $fields
     *  @return array<string, mixed>
     */
    public function createRecord(string $zone, array $fields): array
    {
        return $this->request('POST', $this->zonePath($zone).'/record', $fields);
    }

    /** @param array<string, mixed> $fields */
    public function updateRecord(string $zone, string $id, array $fields): void
    {
        $this->request('PUT', $this->recordPath($zone, $id), $fields);
    }

    public function deleteRecord(string $zone, string $id): void
    {
        $this->request('DELETE', $this->recordPath($zone, $id));
    }

    public function refreshZone(string $zone): void
    {
        $this->request('POST', $this->zonePath($zone).'/refresh');
    }

    /** @param array<string, mixed> $parameters */
    private function request(string $method, string $path, array $parameters = []): mixed
    {
        if ($this->beforeRequest !== null) ($this->beforeRequest)();
        try {
            return $this->api->{strtolower($method)}($path, $parameters ?: null);
        } catch (GuzzleException $exception) {
            $status = $exception instanceof RequestException ? $exception->getResponse()?->getStatusCode() : null;
            $message = match ($status) {
                400 => 'OVH rejected request parameters (HTTP 400). Check type, name, target and TTL.',
                401 => 'OVH rejected authentication (HTTP 401). Check credentials and token expiry.',
                403 => 'OVH access denied (HTTP 403). Check token permissions for this zone and operation.',
                404 => 'OVH zone or record not found (HTTP 404).',
                409 => 'OVH state conflict (HTTP 409). Reload the current configuration.',
                429 => 'OVH API rate limit exceeded (HTTP 429). Try later.',
                null => 'Cannot connect to the OVH API.',
                default => sprintf('OVH API error (HTTP %d).', $status),
            };
            if ($method !== 'GET' && ($status === null || $status >= 500)) {
                $message .= ' Operation outcome may be unknown; inspect state before retrying a write.';
            }

            // Never forward Guzzle messages, request headers, response bodies or previous exceptions.
            throw new ApiException($message, $method !== 'GET' && ($status === null || $status >= 500));
        }
    }

    private function zonePath(string $zone): string
    {
        return '/domain/zone/'.rawurlencode($zone);
    }

    private function recordPath(string $zone, string $id): string
    {
        return $this->zonePath($zone).'/record/'.rawurlencode($id);
    }
}
