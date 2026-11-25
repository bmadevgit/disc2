# Docker on WSL Design

## Intent

Make DISC2 runnable in Docker from WSL without installing PHP on the WSL host, while preserving the repository's local-demo and data-safety rules.

## Goals

- Run PHP 8.5 with PDO SQLite and cURL through Docker Compose.
- Publish the development site only on `127.0.0.1:8760`.
- Keep generated demo data in the ignored host `runtime/` directory.
- Reuse `tools/init.php` and `tools/router.php` rather than creating a second application path.

## Non-goals and anti-goals

- No production image, external database, real credentials, or deployment automation.
- Do not expose the development server on all host interfaces.
- Do not copy runtime data or secrets into the image.

## Constraints

- Follow `AGENTS.md`: synthetic data only, initialize with `php tools/init.php`, loopback access, and PHP lint/HTTP verification.
- The container must accept requests forwarded by Docker while the host port remains loopback-only.

## Approaches

1. **PHP CLI development server (selected):** small image, directly reuses the existing router, and matches the documented development workflow. The router needs a narrowly scoped Docker-development allowance.
2. **Apache image:** avoids the CLI server but duplicates routing/access rules and may expose files the current router denies.
3. **Host networking:** avoids a router change but is less portable across Docker Desktop/WSL configurations.

## Design

Add a PHP 8.5 CLI `Dockerfile` with SQLite and cURL extensions, a Compose service that bind-mounts the repository and publishes `127.0.0.1:8760`, and a small router change that accepts Docker private-network clients only when an explicit development environment flag is set. Document build, initialization, startup, credential lookup, and teardown commands in the README.
