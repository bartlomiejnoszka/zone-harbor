# Installation and first run

## Native source checkout

Requires PHP 8.4.1+, intl, mbstring, JSON and OpenSSL. TUI also needs pcntl and a Unix terminal. Composer is needed to install from source.

```bash
composer install
bin/zone-harbor install
zone-harbor setup
zone-harbor
```

`install` creates a launcher symlink in `~/.local/bin`. Add that directory to PATH if needed. It never edits shell startup files or replaces a regular file. Select another directory with `--bin-dir`; `--replace` upgrades an existing symlink. Keep the application directory because the shortcut points into it. Shell completion is available through `zone-harbor completion bash`, `zsh` or `fish`.

Without an installed shortcut, use `bin/zone-harbor` with the same commands. Existing `php bin/console …` and `php bin/mcp mcp:server default` remain available.

## Dependency-complete bundle

CI builds a versioned `.tar.gz` and SHA-256 checksum. The bundle contains production dependencies, so Composer is not required after extraction; PHP and its extensions are still required. Extract it into a persistent application directory, verify its checksum, then run `bin/zone-harbor install` from there. This development branch produces `zone-harbor-0.2.0-dev.tar.gz`; it is not the published v0.1.0 source archive.

After downloading the matching archive and checksum, install it into a persistent directory:

```bash
shasum -a 256 -c zone-harbor-0.2.0-dev.tar.gz.sha256
mkdir zone-harbor
tar -xzf zone-harbor-0.2.0-dev.tar.gz -C zone-harbor
zone-harbor/bin/zone-harbor install
zone-harbor setup
```

Maintainers build and verify it with:

```bash
php scripts/build-release.php
php scripts/release-smoke.php
```

The build copies an explicit allowlist, installs production dependencies from the lock file and excludes user configuration, operations, caches and development dependencies. Packaging does not publish to Packagist or an image registry.

## Docker without local PHP

```bash
docker compose -f compose.cli.yaml build
docker compose -f compose.cli.yaml run --rm -it cli setup
docker compose -f compose.cli.yaml run --rm -it cli tui
docker compose -f compose.cli.yaml run --rm -T cli doctor --online
```

This configuration needs no pre-created OVH secrets. Setup can enable OVH later. The private named volume `zone-harbor_config` stores settings, credentials and runtime state. Keep that volume when replacing a container. Use `ZONE_HARBOR_CONFIG_VOLUME` and `ZONE_HARBOR_IMAGE` for a different volume/image.

For local MCP client settings:

```bash
docker compose -f compose.cli.yaml run --rm -T cli mcp:config --client=cursor
```

The generated host command starts Docker with the same private volume; it does not mount the Docker socket. Use `--docker-command /absolute/path/to/docker` if the GUI client cannot find Docker on PATH. Client-file updates from a container require an explicit host-mounted `--output` file; otherwise copy the generated JSON into the host client.

See [configuration](configuration.md), [MCP integration](mcp.md) and `zone-harbor doctor` for diagnostics.
