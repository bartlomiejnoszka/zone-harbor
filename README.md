# ZoneHarbor

DNS management and domain health from your terminal. Built with Symfony Console, Symfony TUI and Symfony MCP Bundle.

Manage OVHcloud DNS records through a CLI, a full-screen terminal interface, or MCP. Inspect public DNS, mail authentication records and TLS certificates without an OVH account.

**Experimental v0.1.0.** PHP 8.4.1+ required. Symfony 8.2 development packages and the beta MCP Bundle are deliberately pinned in `composer.lock`; install from the lock file. Only OVH is implemented as a management provider.

## Quick start

```bash
git clone https://github.com/bartlomiejnoszka/zone-harbor.git
cd zone-harbor
composer install
php bin/console domain:check example.com
php bin/console certificate:check example.com --json
php bin/console dns:tui example.com --health
```

Public health checks need Internet DNS/TLS access, but no credentials. Local or blocked networks can produce incomplete reports. Optional mail endpoints, DKIM selectors and certificate thresholds live in the ignored `zone-harbor.local.yaml`; start from [the example](zone-harbor.example.yaml).

For OVH management:

```bash
cp .env.example .env.local
chmod 600 .env.local
# Edit .env.local with your own OVH credentials.
php bin/console zone:list
php bin/console record:list example.com
php bin/console record:add example.com --type=TXT --name=_demo --target='"demo"' --dry-run
php bin/console dns:tui
```

Real writes take a zone lock, save native and normalized backups, record durable intent, then mutate and optionally publish. Backups are required for CLI writes too. OVH publication includes **all pending changes**, including edits made elsewhere. Never automatically retry an uncertain write.

![Simulated terminal session; no real domains or credentials](docs/assets/tui-demo.gif)

## Interfaces

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
