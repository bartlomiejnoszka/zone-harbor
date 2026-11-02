# ZoneHarbor

DNS management and domain health from your terminal. Built with Symfony Console, Symfony TUI and Symfony MCP Bundle.

Manage OVHcloud DNS records through a CLI, a full-screen terminal interface, or MCP. Inspect public DNS, mail authentication records and TLS certificates without an OVH account.

**Development: 0.2.0-dev onboarding.** PHP 8.4.1+ required. Symfony 8.2 development packages and the beta MCP Bundle are deliberately pinned in `composer.lock`; install from the lock file. Only OVH is implemented as a management provider.

## Quick start

```bash
git clone https://github.com/bartlomiejnoszka/zone-harbor.git
cd zone-harbor
composer install
bin/zone-harbor install
zone-harbor setup
zone-harbor
```

Setup offers public health checks without an account or OVH management with hidden credential input. Settings and runtime data live outside the checkout. `zone-harbor` opens the TUI at your preferred domain; `zone-harbor doctor --online` diagnoses local MCP and read-only OVH connectivity.

```bash
zone-harbor domain:check example.com
zone-harbor mcp:config --client=cursor
zone-harbor mcp:config --client=cursor --write
```

MCP client settings use absolute paths and contain no OVH credentials. Preview is the default; `--write` merges only the ZoneHarbor entry and makes a private backup. Local MCP uses STDIO, so no web endpoint is needed.

Docker onboarding needs no local PHP or pre-created OVH secrets:

```bash
docker compose -f compose.cli.yaml build
docker compose -f compose.cli.yaml run --rm -it cli setup
docker compose -f compose.cli.yaml run --rm -it cli tui
```

See [installation](docs/installation.md) for dependency-complete bundles, shortcut installation and Docker MCP settings, and [configuration](docs/configuration.md) for file locations and automation. Legacy checkout configuration and command names remain supported.

Real writes take a zone lock, save native and normalized backups, record durable intent, then mutate and optionally publish. OVH publication includes **all pending changes**, including edits made elsewhere. Never automatically retry an uncertain write.

![Simulated terminal session; no real domains or credentials](docs/assets/tui-demo.gif)

## Interfaces

- [Installation and setup](docs/installation.md)
- [Configuration format and schema](docs/configuration.md)
- [CLI commands and safe writes](docs/cli.md)
- [Terminal UI and keyboard controls](docs/tui.md)
- [Domain health checks and limitations](docs/health.md)
- [MCP over STDIO or authenticated HTTP](docs/mcp.md)
- [Docker and Traefik](docs/deployment.md)
- [Architecture and adding a provider](docs/architecture.md)
- [Tests and snapshots](docs/testing.md)

## Development

```bash
composer install
composer check
python3 scripts/tui-smoke.py
php scripts/mcp-smoke.php
```

See [contributing](CONTRIBUTING.md), [security reporting](SECURITY.md), and the [changelog](CHANGELOG.md). MIT licensed; a personal project by Bartłomiej Noszka. Not affiliated with OVHcloud or Symfony.
