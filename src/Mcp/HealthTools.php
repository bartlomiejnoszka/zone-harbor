<?php

declare(strict_types=1);

namespace OvhDns\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use OvhDns\Health\DomainChecker;

final class HealthTools
{
    public function __construct(private readonly DomainChecker $checker) {}
    #[McpTool(name: 'dns_domain_check', description: 'Read-only public DNS, mail authentication publication and TLS audit. No OVH credentials required. Findings are evidence, not proof of delivered mail authentication. No private network targets.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: DnsTools::OUTPUT)]
    public function domain(string $domain): CallToolResult { return $this->result(fn () => $this->checker->domain($domain)); }
    #[McpTool(name: 'tls_certificate_check', description: 'Read-only certificate inspection. Supports TLS 443/465/993 and SMTP STARTTLS 587. No credentials or mail are sent. Probes public addresses only.', annotations: new ToolAnnotations(readOnlyHint: true), outputSchema: DnsTools::OUTPUT)]
    public function certificate(string $host, int $port = 443): CallToolResult { return $this->result(fn () => $this->checker->certificate($host, $port)); }
    private function result(callable $operation): CallToolResult
    {
        $error = false;
        try { $data = $operation()->jsonSerialize(); }
        catch (\InvalidArgumentException $e) { $data = ['status' => 'invalid_input', 'error' => $e->getMessage()]; $error = true; }
        catch (\Throwable) { $data = ['status' => 'error', 'error' => 'Health inspection could not complete.']; $error = true; }
        return new CallToolResult([new TextContent(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE))], $error, $data);
    }
}
