# Verification

```bash
composer check
composer audit --locked
python3 scripts/tui-smoke.py
php scripts/mcp-smoke.php
```

`composer check` runs strict Composer validation, PHPStan level 5 and PHPUnit. Tests use mocked OVH responses, an in-memory provider, fake DNS/TLS/clock transports, public certificate fixtures generated in memory, local MCP HTTP servers and a virtual terminal. CI needs no production credentials and performs no DNS writes.

Coverage includes write locks/backups, publication failures, unknown outcomes, stale records, opaque provider references, default TTLs, exact RDATA, delegation disagreement, recursive caches, DNS failures, SPF/DMARC/DKIM publication, certificate thresholds and blocked private destinations.

TUI snapshots capture text and ANSI styling at wide and compact sizes, including health views and evidence. Missing or changed baselines fail. Update only after inspecting the change:

```bash
composer snapshots:update
composer test:snapshots
```

Snapshot updates are explicitly blocked in CI. The PTY smoke script exercises terminal restoration and simulated CRUD. It never contacts OVH unless a live read-only target is explicitly provided.

To regenerate the public simulated recording:

```bash
python3 scripts/tui-smoke.py --record=operations/tui-demo.cast
agg operations/tui-demo.cast docs/assets/tui-demo.gif
```

Review the recording for private values before publishing. Never record real credentials or operational domains for the public repository.
