# Changelog

## Unreleased — 0.2.0-dev

- Single `zone-harbor` launcher, user-local installation shortcut and default TUI entry.
- Interactive setup with hidden OVH credentials, preferred domain, mail expectations and MCP settings generation.
- Shared external user configuration, JSON Schema validation and portable cache/backup paths.
- `doctor`, `config:validate`, local `mcp:serve`, and safe client configuration merging for generic JSON, Claude Desktop and Cursor.
- Credential-free Docker onboarding with a private configuration volume and generated Docker MCP settings.
- Dependency-complete PHP release bundle, checksum and packaged-runtime verification.
- Legacy commands and checkout configuration remain available. Remote HTTP deployment wizard is deferred.

## 0.1.0

First public experimental release under the ZoneHarbor name.

- English CLI, full-screen TUI and authenticated MCP HTTP/STDIO interfaces.
- OVH record management with shared backups, locking, stale-preview checks and explicit publication outcomes.
- Provider extension contract with typed zones, records, mutations and explicit capabilities; OVH is the only production adapter.
- Credential-free public DNS, mail-record and TLS health checks through CLI, TUI and two read-only MCP tools.
- Configurable mail expectations, DKIM selectors, mail endpoints and relative certificate expiry thresholds.
- Deterministic tests, terminal snapshots, Docker/Traefik deployment and CI.

Symfony 8.2 development packages and the beta MCP Bundle remain pinned in the lock file. Existing management command names, OVH JSON fields, exit codes and eight OVH MCP tool names are retained. Real CLI writes now also require backup/export permissions and a writable operation directory.
