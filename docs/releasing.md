# Releasing

1. Set the version in `ApplicationFactory` and the MCP server configuration and add changelog notes. Keep Symfony development dependencies pinned; review dependency updates deliberately.
2. Run `composer check`, `composer audit --locked`, native PTY/MCP smoke checks and Docker validation.
3. Scan staged files and the full public Git history for credentials. Review examples and any recordings for private infrastructure.
4. Push the reviewed commit and wait for all hosted CI jobs to pass.
5. Tag `v0.1.0` (or the intended next version) on that exact green commit and publish a GitHub release with its notes.

Initial distribution is Git clone plus Composer or local Docker builds. No Packagist package, PHAR, or registry image is published automatically.
