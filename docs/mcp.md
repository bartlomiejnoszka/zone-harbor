# MCP

Local integration starts with the setup wizard and a generated configuration:

```bash
zone-harbor setup
zone-harbor mcp:config --client=claude-desktop
zone-harbor mcp:config --client=cursor
zone-harbor mcp:config --client=cursor --write
zone-harbor doctor --online
```

Generic JSON is available with `mcp:config` (no client flag). `--output PATH` selects a client file. Preview prints only the proposed ZoneHarbor entry, keeping unrelated client secrets out of output. `--write` merges under a lock, makes a private backup, preserves unrelated servers and rejects malformed JSON or symlink targets. Restart the client after saving. Presets follow the documented [Claude Desktop](https://modelcontextprotocol.io/docs/develop/connect-local-servers) and [Cursor](https://cursor.com/docs/mcp) formats.

The generated entry contains absolute PHP/launcher/configuration paths. Stable launcher/PHP paths are preferred when they resolve to the running installation. If paths change during an upgrade, regenerate the client settings. OVH credentials stay in ZoneHarbor's private configuration. Native local MCP starts with `zone-harbor mcp:serve`; startup never opens setup or prints banners to protocol stdout.

Docker onboarding automatically generates a Docker host command using the named user-configuration volume. Override `--docker-command`, `--docker-image` and `--docker-volume` when needed; see [installation](installation.md). Keep STDIO free of a TTY (`-i`, not `-it`).

The existing lower-level entrypoint remains available:

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
