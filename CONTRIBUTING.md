# Contributing

Open an issue for substantial behavior or provider changes before starting a large implementation. Keep issues, code comments and documentation in English. Use reserved example domains and synthetic data; never post credentials, private DNS zones, backups or live recordings.

Use PHP 8.4.1+ and install the committed Composer lock file. Run `composer check`, `python3 scripts/tui-smoke.py` and `php scripts/mcp-smoke.php` before submitting a PR. Add focused tests for behavior changes. Review snapshots before explicitly updating them; never update snapshots in CI or suppress PHPStan findings with a baseline.

Keep business behavior shared between interfaces. Provider adapters own API field mapping and sanitized errors; all mutations go through `WriteExecutor`. Preserve preview, confirmation, backup, locking and unknown-outcome behavior. Do not test writes against production accounts. Add providers only with meaningful contract and transport tests.

PRs should explain the problem, resulting behavior and validation. Contributions are under the MIT license.
