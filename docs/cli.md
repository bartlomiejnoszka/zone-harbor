# CLI

```bash
php bin/console list
php bin/console help record:add
php bin/console zone:list --json
php bin/console zone:export example.com > example.com.zone
php bin/console record:list example.com --type=MX --name=@ --json
php bin/console record:show example.com 12345 --json
php bin/console record:add example.com --type=TXT --name=_demo --target='"demo"' --dry-run
php bin/console record:add example.com --type=A --name=www --target=203.0.113.10 --ttl=300
php bin/console record:update example.com 12345 --ttl=600 --no-refresh
php bin/console record:delete example.com 12345 --dry-run
php bin/console record:delete example.com 12345 --force -n
php bin/console zone:refresh example.com --dry-run
php bin/console zone:refresh example.com
php bin/console domain:check example.com --json
php bin/console certificate:check mail.example.com --port=993 --json
```

Use ASCII/punycode domains. Record names are relative; `@` is the apex. TTL `0` selects the zone default. TXT targets use literal zone-file quotes. Updating a record changes only supplied fields; its type cannot change.

Add/update/refresh previews require no API connection. A delete preview reads the exact record. Non-interactive deletion requires `--force`. An interactive delete shows the record and asks for confirmation; an intervening edit rejects the operation.

## Write outcomes

Real writes require writable `operations/mcp` and OVH permissions for export and record reads as well as the chosen mutation and refresh. Each operation saves `before.zone` (OVH), `before.json`, `plan.json`, `intent.json` and `result.json` with restricted local permissions. Backups can contain sensitive DNS data; keep them private.

- `publication_accepted`: provider accepted publication; public propagation is separate.
- `saved_pending_publication`: saved with `--no-refresh`.
- `saved_publication_unconfirmed`: saved, refresh failed. Inspect state, then refresh; do not repeat the mutation.
- `outcome_unknown`: inspect provider state and backup before deciding what to do. No retry or rollback is automatic.
- `failed`: the operation was rejected or preparation prevented the write.

OVH refresh publishes **all** pending changes, including external edits. The local lock coordinates this checkout's CLI/TUI/MCP; it does not prevent external edits or writes from other machines.

Management commands retain exit codes `0` success, `1` operation/configuration failure, `2` invalid input. Health commands have separate [exit semantics](health.md).
