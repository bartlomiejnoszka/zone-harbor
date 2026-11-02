# Terminal interface

```bash
zone-harbor
zone-harbor tui
php bin/console dns:tui
php bin/console dns:tui example.com
php bin/console dns:tui example.com --health
php bin/console dns:tui --health
```

The launcher opens the TUI at `default_domain` from user settings. Public-health mode automatically uses the read-only Health view. Run `zone-harbor setup` to change the preferred domain or account.

Use an interactive terminal at least 80×24. Health mode needs no OVH credentials and accepts a domain argument or prompts for one. It remains read-only.

| Key | Action |
|---|---|
| 1 / 2 / Tab | Zones / records / switch pane |
| Up / Down / PageUp / PageDown | Navigate or scroll |
| Left / Right | Change record type group |
| / | Live filter; Escape restores the previous filter |
| Enter | Open selected record, action or health evidence |
| Ctrl+P | Search the command palette |
| a / e / d | Add / edit / delete with preview |
| p | Preview publication of all pending changes |
| h | Audit domain health; in health view choose another domain |
| r / F5 | Reload; in health view rerun the audit |
| v | Toggle record preview |
| ? | Help |
| Escape | Cancel a read, close a dialog or return |
| q / Ctrl+C | Exit after any active write completes |

Forms use native widgets. Tab moves fields; long TXT values wrap in the editor. Before any write the exact proposed change is shown. Only `y` confirms; Enter does not confirm a write. Changed records reject stale previews. A running write cannot be cancelled from the UI.

The Health view shows findings with Enter for sources, evidence and remediation. Read cancellation never mutates DNS. The worker uses JSON over stdin/stdout; credentials are not passed as command arguments.
