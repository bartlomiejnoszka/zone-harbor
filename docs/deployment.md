# Docker and Traefik

```bash
cp .env.docker.example .env.docker.local
bin/setup-docker-secrets
# Configure your own OVH secret files and a private HTTP bearer token.
docker compose --env-file .env.docker.local build
docker compose --env-file .env.docker.local up -d http
curl http://127.0.0.1:8080/healthz
```

The default HTTP listener is localhost only. The runtime uses a pinned FrankenPHP/PHP 8.5 image, UID 10001, read-only root filesystem, no Linux capabilities, tmpfs and dedicated cache/operation volumes. Credentials are secret files, excluded from Git and build context. The setup script does not grant OVH permissions; create a narrowly scoped token yourself.

Public checks can run without any secrets:

```bash
docker build --target runtime -t zone-harbor:local .
docker run --rm --read-only --tmpfs /tmp zone-harbor:local php bin/console domain:check example.com --json
docker run --rm -it --read-only --tmpfs /tmp zone-harbor:local php bin/console dns:tui example.com --health
```

For local health settings, bind-mount your ignored configuration read-only at `/app/zone-harbor.local.yaml`. Do not bake it into an image.

For remote access, set `MCP_HOST`, Traefik network/entrypoint/certificate resolver and exact allowed host/origin values, then:

```bash
docker compose --env-file .env.docker.local -f compose.yaml -f compose.traefik.yaml up -d --build http
```

The overlay exposes exactly `/mcp` through TLS on an existing external Traefik network. Bearer authentication remains required. Only trust actual reverse-proxy addresses via `TRUSTED_PROXIES`. Secrets directory permissions are restrictive; Docker secret file permissions permit the non-root container to read the mounted files. See [security](../SECURITY.md) before exposing the endpoint.

Back up the private `operations` volume. Coordinate multi-host writers externally; filesystem locks coordinate only the shared local operation directory. New images require a deliberate rebuild/recreate; no remote service is changed by editing this checkout.
