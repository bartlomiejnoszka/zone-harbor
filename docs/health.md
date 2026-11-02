# Domain health

```bash
php bin/console domain:check example.com
php bin/console certificate:check example.com --json
php bin/console certificate:check mail.example.com --port=587 --json
php bin/console dns:tui example.com --health
```

New user configuration stores health settings under `health:`; select it using `--config` or create it with `zone-harbor setup`. The legacy `zone-harbor.local.yaml` format remains available when no external profile is selected; its template is `examples/config/legacy-health.yaml`.

These operations never change DNS, authenticate to a mailbox, or send messages. With no provider credentials, they use public DNS/TLS only. When OVH credentials are configured, an optional comparison distinguishes account configuration from published authoritative answers. A provider failure leaves public findings available.

## Findings

Reports contain a target, UTC observation timestamp, overall status and checks with stable IDs, status, summary, evidence, source and remediation. `PASS`, `WARN`, `FAIL`, `UNKNOWN`, `SKIP` distinguish confirmed results from incomplete or unconfigured checks. Overall severity is FAIL, UNKNOWN, WARN, PASS. SKIP is neutral.

| Exit | Meaning |
|---|---|
| 0 | No confirmed failure or incomplete check; warnings may remain |
| 1 | At least one confirmed failure |
| 2 | Invalid input or configuration |
| 3 | Incomplete checks without a confirmed failure |

The DNS audit compares parent delegation, authoritative NS/SOA and relevant apex, www, MX and authentication RRsets. Google and Cloudflare recursive answers are compared with the authoritative baseline, ignoring ordering and TTL countdown. It is a targeted audit, not enumeration of every DNS name. It does not fully validate DNSSEC or prove worldwide propagation.

Mail checks inspect MX targets, SPF policies/includes and lookup limits, DMARC tags/report destinations and **configured** DKIM selectors. `mail: google` checks Google routing expectations; `mail: none` supports a null MX. With no explicit expectation, mail is inferred from MX and a web-only domain is not assumed to need mail. DKIM selectors cannot be reliably discovered. SPF macros cannot be fully evaluated without message context and may produce UNKNOWN. DMARC monitoring (`p=none`) is a warning, not a failed mail setup.

Published keys and policies do not prove signing is enabled or a delivered message passes SPF/DKIM/DMARC. Verify `Authentication-Results` at an independent recipient. Legacy Google Talk records are review suggestions; no record is automatically removed.

## Certificates and limits

Resolving apex/www HTTPS endpoints are checked automatically. Mail probes must be configured under `health.domains.<domain>.endpoints` in a user configuration (or `domains.<domain>.endpoints` in the legacy file): IMAP TLS 993, SMTP TLS 465, SMTP STARTTLS 587. Standalone certificate checks also support HTTPS 443.

The inspector pins a validated public IP, retains the hostname as SNI, verifies the chain/name using the platform trust store and inspects validity dates. A separate inspection handshake can retain expiry evidence after verification fails. No application credentials are sent. One address is probed per endpoint; additional addresses produce a coverage warning.

Expiry uses the fraction of total certificate lifetime remaining: **WARN below 25%**, **FAIL below 10%**, and FAIL for expired or not-yet-valid certificates. Both ratios are configurable. A network timeout is UNKNOWN.

Reports have a 30-second network budget, 2-second DNS query limit, 3-second connection limit, up to six authoritative servers, eight TLS endpoints and eight DKIM selectors. Omitted checks are incomplete. Private, loopback, link-local and special-use probe addresses are blocked. Rerun from the affected network to distinguish network restrictions from public configuration errors.

Registrar renewal dates, automated monitoring, certificate issuance/renewal and automatic DNS cleanup are outside v0.1.0.
