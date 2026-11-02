# Changelog

## 0.1.0

First public experimental release under the ZoneHarbor name.

- English CLI, full-screen TUI and authenticated MCP HTTP/STDIO interfaces.
- OVH record management with shared backups, locking, stale-preview checks and explicit publication outcomes.
- Provider extension contract with typed zones, records, mutations and explicit capabilities; OVH is the only production adapter.
- Credential-free public DNS, mail-record and TLS health checks through CLI, TUI and two read-only MCP tools.
- Configurable mail expectations, DKIM selectors, mail endpoints and relative certificate expiry thresholds.
- Deterministic tests, terminal snapshots, Docker/Traefik deployment and CI.

Symfony 8.2 development packages and the beta MCP Bundle remain pinned in the lock file. Existing management command names, OVH JSON fields, exit codes and eight OVH MCP tool names are retained. Real CLI writes now also require backup/export permissions and a writable operation directory.
