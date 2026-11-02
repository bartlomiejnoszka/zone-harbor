# User configuration

Run `zone-harbor setup` once. Without a command, an interactive `zone-harbor` opens the TUI; a fresh installation opens setup. Piped/non-interactive invocations show help instead of opening a wizard. `bin/console` keeps its previous default of listing commands.

```bash
zone-harbor setup
zone-harbor doctor
zone-harbor doctor --online
zone-harbor config:validate
zone-harbor completion zsh
```

## Location and precedence

Configuration selection is `--config PATH`, then `ZONE_HARBOR_CONFIG`, then the user default. `--config` works before or after the command. Relative configuration, credentials and storage paths are resolved to local absolute paths; credentials/storage paths are relative to the selected YAML file.

- With `XDG_CONFIG_HOME`: `$XDG_CONFIG_HOME/zone-harbor/config.yaml`.
- macOS default: `~/Library/Application Support/ZoneHarbor/config.yaml`.
- Linux default: `~/.config/zone-harbor/config.yaml`.

Runtime caches, logs and operation backups default to `state/` beside the YAML. Set `storage.directory` to change that location. The CLI, TUI worker and MCP share configuration and backup locks. Cache namespaces distinguish installations, selected configuration, version and MCP host/origin settings.

When no external configuration is selected or found, legacy checkout `.env.local`, `zone-harbor.local.yaml`, `var/` and `operations/mcp` still work. An explicit missing or invalid configuration fails instead of silently using a different account. Setup, help, diagnostics and validation remain available to repair settings.

```bash
zone-harbor --config /etc/zone-harbor/config.yaml tui
zone-harbor doctor --config /etc/zone-harbor/config.yaml --json
```

## Format

[The example](../zone-harbor.example.yaml) and [JSON Schema](../schema/zone-harbor.schema.json) define version 1. Runtime validation uses that same schema, followed by DNS/health semantic validation. Examples under `examples/config/` are checked in tests without live credentials.

```yaml
version: 1
mode: health
default_domain: example.com
health:
  certificate:
    warning_remaining: 0.25
    critical_remaining: 0.10
```

`mode: health` uses no provider account and blocks real DNS writes across CLI/TUI/MCP, even when OVH environment variables exist. Previews remain available. `mode: ovh` enables the existing account operations and write safeguards.

Setup can record mail-provider expectations and DKIM selectors for the preferred domain. Add optional mail TLS endpoints under `health.domains.<domain>.endpoints`; see [health checks](health.md). Multi-account management and a remote HTTP deployment wizard are outside this onboarding release.

## Credentials and repeatable setup

OVH setup asks for the region and three API credentials using hidden input with no visible-input fallback. Create a scoped API token through the OVH API portal. Values are written to a separate file with permission 0600; YAML stores only the relative file reference. Existing credentials remain active until the new configuration is atomically saved. Old credential files and private backups are retained.

```yaml
version: 1
mode: ovh
ovh:
  endpoint: ovh-eu
  credentials_file: credentials.env
```

Real process environment variables override dotenv values. Existing `*_FILE` support remains authoritative over the corresponding environment values. OVH secrets are not loaded in public-health mode. Remote MCP authentication and allowed hosts/origins remain deployment environment settings.

Re-running setup preserves health settings and keeps credentials when hidden answers are left blank. Changed configuration gets a private backup; an intervening edit rejects a stale save. Setup does not change DNS or shell startup files. `doctor` checks local requirements; `doctor --online` additionally tests STDIO discovery and, in OVH mode, reads the zone list.

For automation, choose an explicit mode and supply OVH credentials via protected environment/secret sources:

```bash
zone-harbor setup --config /tmp/demo/config.yaml --mode=health --domain=example.com --skip-checks -n
```

Native onboarding/TUI is supported on macOS and Linux. The container runtime is Linux. Remote HTTP still follows [deployment instructions](deployment.md).
