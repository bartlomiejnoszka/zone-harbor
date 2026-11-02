<?php

declare(strict_types=1);

namespace OvhDns\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use OvhDns\ConfigurationException;
use OvhDns\Operation\WriteExecutor;
use OvhDns\Operation\WritePreparationException;
use OvhDns\Provider\ProviderException;
use OvhDns\Dns\DnsService;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Throwable;

final class DnsTools
{
    // A single envelope allows callers to inspect partial outcomes without parsing prose.
    public const OUTPUT = ['type' => 'object', 'required' => ['status'], 'properties' => ['status' => ['type' => 'string']], 'additionalProperties' => true];

    public function __construct(private readonly DnsService $dns, private readonly WriteExecutor $writes) {}

    #[McpTool(name: 'ovh_zone_list', description: 'List existing ZoneHarbor zones accessible to the configured account.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: self::OUTPUT)]
    public function zones(): CallToolResult
    {
        return $this->result(fn () => ['status' => 'ok', 'zones' => $this->dns->listZones()]);
    }

    #[McpTool(name: 'ovh_zone_export', description: 'Export an existing ZoneHarbor zone as zone-file text.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: self::OUTPUT)]
    public function export(#[Schema(description: 'ASCII/punycode zone name, e.g. example.com.')] string $zone): CallToolResult
    {
        return $this->result(fn () => ['status' => 'ok', 'zoneFile' => $this->dns->exportZone($zone)]);
    }

    #[McpTool(name: 'ovh_record_list', description: 'List records with exact optional type and relative-name filters. @ means zone apex.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: self::OUTPUT)]
    public function records(string $zone, ?string $type = null, ?string $name = null): CallToolResult
    {
        return $this->result(fn () => ['status' => 'ok', 'records' => $this->dns->listRecords($zone, $type, $name)]);
    }

    #[McpTool(name: 'ovh_record_show', description: 'Read one existing DNS record by its OVH ID.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: self::OUTPUT)]
    public function record(string $zone, #[Schema(description: 'Positive OVH record ID encoded as a string.')] string $id): CallToolResult
    {
        return $this->result(fn () => ['status' => 'ok', 'record' => $this->dns->getRecord($zone, $id)]);
    }

    #[McpTool(name: 'ovh_record_add', description: 'Preview or add a DNS record. Defaults to preview. A real write is backed up and publishes ALL pending zone changes unless refresh=false. TXT target must contain zone-file quotes. Never retry an unknown write automatically.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false), outputSchema: self::OUTPUT)]
    public function add(string $zone, string $type, string $name, #[Schema(description: 'OVH zone-file value, e.g. "v=spf1 include:_spf.google.com ~all" with literal quotes for TXT.')] string $target, #[Schema(minimum: 0, maximum: 2147483647)] int $ttl = 0, bool $refresh = true, bool $dryRun = true): CallToolResult
    {
        return $this->result(fn () => $this->writes->run('record:add', $zone, fields: ['fieldType' => $type, 'subDomain' => $name, 'target' => $target, 'ttl' => $ttl], refresh: $refresh, dryRun: $dryRun));
    }

    #[McpTool(name: 'ovh_record_update', description: 'Preview or update only supplied fields. Record type cannot change. Real writes publish ALL pending changes unless refresh=false. Defaults to preview.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false), outputSchema: self::OUTPUT)]
    public function update(string $zone, string $id, ?string $name = null, ?string $target = null, #[Schema(minimum: 0, maximum: 2147483647)] ?int $ttl = null, bool $refresh = true, bool $dryRun = true): CallToolResult
    {
        $fields = array_filter(['subDomain' => $name, 'target' => $target, 'ttl' => $ttl], static fn ($value) => $value !== null);
        return $this->result(fn () => $this->writes->run('record:update', $zone, $id, $fields, $refresh, $dryRun));
    }

    #[McpTool(name: 'ovh_record_delete', description: 'Preview the exact record before deletion. Execution requires dryRun=false and confirmDelete=true. These flags express caller intent, not proof of human approval. Real writes publish ALL pending zone changes unless refresh=false.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false), outputSchema: self::OUTPUT)]
    public function delete(string $zone, string $id, bool $refresh = true, bool $dryRun = true, bool $confirmDelete = false): CallToolResult
    {
        return $this->result(fn () => $this->writes->run('record:delete', $zone, $id, refresh: $refresh, dryRun: $dryRun, confirmDelete: $confirmDelete));
    }

    #[McpTool(name: 'ovh_zone_refresh', description: 'Preview or publish ALL pending zone changes, including changes made outside this MCP server. Acceptance by OVH does not prove authoritative/public DNS propagation.', annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: false), outputSchema: self::OUTPUT)]
    public function refresh(string $zone, bool $dryRun = true): CallToolResult
    {
        return $this->result(fn () => $this->writes->run('zone:refresh', $zone, dryRun: $dryRun));
    }

    private function result(callable $operation): CallToolResult
    {
        try {
            $data = $operation();
        } catch (InvalidArgumentException|\InvalidArgumentException $error) {
            $data = ['status' => 'invalid_input', 'error' => $error->getMessage()];
        } catch (ProviderException|ConfigurationException $error) {
            $data = ['status' => $error instanceof ProviderException && $error->outcomeUnknown ? 'outcome_unknown' : 'failed', 'error' => $error->getMessage()];
        } catch (WritePreparationException $error) {
            $data = ['status' => 'failed', 'error' => $error->getMessage()];
        } catch (Throwable) {
            // Never return raw SDK, filesystem, container or authentication exceptions.
            $data = ['status' => 'outcome_unknown', 'error' => 'Operation could not be confirmed. Inspect zone state before retrying; no automatic retry was made.'];
        }
        return new CallToolResult([new TextContent(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))], isset($data['error']), $data);
    }
}
