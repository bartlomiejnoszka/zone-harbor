# Releasing

1. Set the version in `src/Version.php` and add changelog notes. Keep Symfony development dependencies pinned; review dependency updates deliberately.
2. Run `composer check`, `composer audit --locked`, native TUI/setup PTY and MCP smoke checks and Docker validation. Build the PHP bundle with `php scripts/build-release.php` and verify it with `php scripts/release-smoke.php`.
3. Scan staged files and the full public Git history for credentials. Review examples and any recordings for private infrastructure.
4. Push the reviewed commit and wait for all hosted CI jobs to pass.
5. Tag `v0.1.0` (or the intended next version) on that exact green commit and publish a GitHub release with its notes, the verified `.tar.gz` bundle and its `.sha256` file.

Development CI uploads a verified dependency-complete PHP bundle and checksum. Publishing a GitHub release or registry image remains a separate action. No Packagist package or PHAR is published automatically.
