# Architecture

ZoneHarbor keeps operation policy in application services so CLI, TUI and MCP share behavior.

```mermaid
flowchart TD
    CLI[Symfony Console] --> DNS[DnsService]
    CLI --> W[WriteExecutor]
    TUI[Symfony TUI] --> Worker[JSON subprocess worker]
    Worker --> W
    MCP[Symfony MCP Bundle] --> W
    W --> DNS
    DNS --> P[DnsProvider contract]
    P --> OVH[OVH adapter and official SDK]
    CLI --> H[Read-only health engine]
    Worker --> H
    MCP --> H
    H --> Resolver[NetDNS2 resolver adapter]
    H --> TLS[PHP streams and OpenSSL]
```

## Why these boundaries

- `DnsProvider` deals in typed zones, records and partial mutations. Record references are opaque strings, TTL `null` means provider default, and RDATA is preserved. The OVH adapter owns numeric ID validation and SDK field mapping. Legacy CLI/MCP serializers retain the original OVH wire fields.
- Capabilities declare record types, explicit publication and native export. Unsupported actions fail explicitly. Providers without explicit publication apply changes immediately. Their normalized backup is still mandatory.
- `WriteExecutor` owns validation, account/provider/zone locking, backups, durable intent, stale-preview checks and publication outcomes. OVH native export failure prevents mutation. A failed refresh never retries the record mutation. Unknown outcomes carry a typed flag, independent of display language.
- The official `ovh/ovh` SDK handles authentication and request signing. Reimplementing that protocol would add maintenance without helping the DNS model. The adapter sanitizes transport errors and never forwards secrets or raw responses.
- Health checks are independent of account credentials. Injectable DNS, TLS and clock interfaces make findings deterministic in tests. NetDNS2 supplies a maintained pure-PHP DNS implementation without requiring `dig`; PHP/OpenSSL supplies the platform trust store and SNI.
- TUI reads and writes run in a subprocess, keeping terminal input responsive. Reads can be cancelled; active writes must finish or be inspected afterward.
- HTTP MCP is a narrow authenticated endpoint, not a web admin panel. `/healthz` is liveness only; it never calls OVH or scans domains.

## Adding a provider

Implement `OvhDns\Provider\DnsProvider`, including sanitized errors and `Capabilities`. Preserve exact RDATA and provider metadata, map default TTL explicitly, validate references before network access and never retry uncertain writes. Wire the provider into `DnsService`; `tests/ProviderTest.php` and its in-memory implementation demonstrate the contract. Supply adapter tests and run the same operation safety tests against the new provider.

Only OVH is wired into production in v0.1.0. There is no plugin loader or multi-account UI. A second real provider should drive those decisions. The existing `OvhDns` PHP namespace is retained for source compatibility; product and lowercase identifiers are **ZoneHarbor** and **zone-harbor**.
