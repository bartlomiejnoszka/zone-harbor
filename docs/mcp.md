# MCP

```bash
php bin/mcp mcp:server default
php scripts/mcp-smoke.php
```

The Symfony MCP Bundle serves STDIO and HTTP `/mcp`. STDIO must remain protocol-only stdout; keep transport diagnostics on stderr. Configure a client to run PHP with `bin/mcp mcp:server default` from the project directory. For Docker use `docker compose --profile stdio run --rm --no-deps -T stdio`.

Existing tool names remain compatible: `ovh_zone_list`, `ovh_zone_export`, `ovh_zone_refresh`, `ovh_record_list`, `ovh_record_show`, `ovh_record_add`, `ovh_record_update`, `ovh_record_delete`.

New read-only tools: `dns_domain_check(domain)` and `tls_certificate_check(host, port=443)`. Health findings, including FAIL/UNKNOWN, are successful audit results; invalid input and execution errors use MCP error results. Reports match CLI JSON. The tools restrict probes to public Internet targets.

Writes default to `dryRun=true`. Real deletion also requires `confirmDelete=true`. Caller flags express intent, not proof of human approval. Review previews and partial outcomes; never retry uncertain writes automatically. Refresh publishes every pending zone change.

HTTP requires a bearer token of at least 32 characters, explicit allowed hostnames, and optionally explicit allowed origins. Generate private local secrets using `bin/setup-docker-secrets`. Never configure wildcard host/origin allowlists or publish the token in a URL. Rebuild Symfony's container cache after deployment configuration changes.

`/healthz` is unauthenticated liveness only. It never queries OVH or performs domain checks. Use HTTPS for remote MCP; see [deployment](deployment.md).
