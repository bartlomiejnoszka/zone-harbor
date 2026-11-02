# Security

Report vulnerabilities privately through [GitHub security advisories](https://github.com/bartlomiejnoszka/zone-harbor/security/advisories/new). Do not open a public issue containing credentials or exploitable deployment details. Only the latest experimental release is supported; there is no response-time guarantee.

Use narrowly scoped OVH tokens and keep credentials in ignored local configuration or Docker secrets. Never expose raw SDK errors, request headers or environment values. Backups may contain private DNS data and belong outside public artifacts.

HTTP MCP requires HTTPS, a strong bearer token and explicit host/origin allowlists. Caller confirmation flags are not proof of human approval. Avoid granting untrusted clients write access. Public health checks reject private and special-use network targets; they do not replace a deployment's network policy.

If a credential is exposed, revoke it at its issuer and inspect its access history. Removing it from a file or a later commit does not revoke it.
