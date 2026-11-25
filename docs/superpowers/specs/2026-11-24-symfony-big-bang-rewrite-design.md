# DISC2 Symfony Big-Bang Rewrite Design

## Intent

Replace the procedural DISC2 application with a new Symfony application in one controlled cutover. The replacement must run in Docker/WSL for development and at the existing IIS Server-A location, while preserving the current database, business rules, access boundaries, and user-facing URL contract.

## Confirmed decisions

- Use approach B: a complete rewrite, not an incremental legacy bridge.
- Delete and do not reuse the previous Symfony draft.
- Support both Docker/WSL and IIS Server-A at `C:\inetpub\wwwroot\exam\disc2`.
- Keep WSL source edits live in Docker through a bind mount.
- Do not use `huashu-design` to modify the production application; its documented scope excludes production web apps and backend systems.
- Cutover may invalidate existing sessions and CSRF tokens; users may sign in again and reopen forms.
- Demo rollback may restore the pre-cutover database backup. Production rollback must retain every post-cutover write and audit record.

## Framework baseline

Use Symfony 7.4 LTS on PHP 8.2 or newer; Docker remains PHP 8.5. Symfony 7.4 is the current LTS and receives security fixes through November 2029, which fits a long-lived internal IIS application better than a short-support feature release.

Primary packages: FrameworkBundle, SecurityBundle, TwigBundle, Form, Validator, Csrf, Asset, Monolog, Runtime, and PHPUnit/BrowserKit for tests. Persistence uses PDO repositories over SQLite for the first release; Doctrine ORM is not introduced because the existing schema and SQL semantics are part of the compatibility boundary.

## Goals

- One Symfony front controller and no runtime inclusion of legacy PHP pages.
- Equivalent login, sessions, rate limiting, roles, case scoping, identity masking, CSRF, auditing, workflow deadlines, and data writes.
- Preserve the existing SQLite schema and additive migration history.
- Preserve existing public/bookmarked paths, including `.php` URLs, as Symfony route aliases where applicable.
- Produce a self-contained IIS release artifact with locked Composer dependencies.
- Provide repeatable automated tests against synthetic data before cutover.

## Non-goals and anti-goals

- No legacy controller, procedural-page forwarding, or mixed runtime.
- No schema redesign, database engine change, visual redesign, or real-data development.
- No automatic production migration at web-request time.
- No secrets, production database, caches, logs, or runtime credentials inside Git or the release artifact.
- Do not remove the old production release until the new artifact passes staging/UAT and a rollback package is ready.

## Compatibility contract

The new Symfony routes keep the meaningful existing paths so bookmarks and form targets remain valid: `/`, `/index.php`, `/login.php`, `/logout.php`, `/case.php`, administrative pages, read pages, save endpoints, and redirect helpers such as `/i.php` and `/users.php`. Extensionless Symfony routes may exist for new internal links, but `.php` aliases remain for the first release.

Only known public entry points and static assets are routed. Include/helper files, `src/`, `config/`, `templates/`, `vendor/`, database files, SQL files, environment files, logs, and secrets must never be directly served.

## Target structure

```text
bin/console
config/
migrations/
public/
  index.php
  assets/
  web.config
src/
  Application/
  Controller/
  Domain/
  Infrastructure/Persistence/
  Security/
templates/
tests/
tools/
composer.json
composer.lock
```

- `Domain`: deadline calculations, case states, access decisions, and value objects without Symfony or PDO dependencies.
- `Application`: explicit read/write use cases and transaction boundaries.
- `Infrastructure/Persistence`: parameterized PDO/SQLite repositories mapped to the existing tables.
- `Security`: authenticated identity, password/BMA login integration, voters, CSRF, rate limiting, and subject-identity visibility.
- `Controller`: thin request/response translation; no SQL or business calculations.
- `templates`: Twig views using the existing visual language and assets without redesigning them.

## Rewrite workspace and cutover model

Development happens on a dedicated rewrite branch/worktree. The legacy source remains read-only reference and an oracle for characterization tests while the new application is built. The new application does not call legacy code at runtime.

Before final cutover, the branch replaces root procedural entry points with the Symfony structure. Old root PHP pages are deleted only after the new implementation passes the full parity suite. This is a source-control cutover, not a request-by-request migration.

## Data and migration strategy

- Continue using the current SQLite tables and `uat_schema_migrations` records.
- Build repositories around current columns and queries; do not infer a new normalized schema during the rewrite.
- Port the migration runner into a Symfony console command that applies only versioned SQL files and runs integrity/foreign-key checks.
- Development uses `runtime/disc2-dev.sqlite`; IIS uses a configured database path outside `public/`.
- Before production deployment: stop writes, make one SQLite online backup, verify integrity, deploy code, run migrations once from CLI, run smoke tests, then reopen writes.
- Production migrations in the first release must remain readable by the previous production code so rollback can switch code packages without restoring the database or losing post-cutover writes.
- Every write use case owns its transaction and audit entry. Failure must roll back both business data and audit changes.

## Security design

- Preserve the current session cookie scope and subpath behavior so `/exam/disc2` works on IIS.
- Session continuity across the cutover is not required. Expire the old session/cookie, require a fresh login, and reject forms carrying pre-cutover CSRF tokens.
- Use Symfony authentication with the existing demo/password and BMA production login contracts; production keys remain in the external config.
- Implement voters for administrator, executive/unit head, group head, case owner, per-case read/write, and subject identity visibility.
- Require CSRF tokens for every state-changing browser request and reject invalid tokens consistently.
- Keep login failure logging and the current 15-minute/five-failure IP rate limit unless a parity test proves a deliberate change.
- Add response security headers and prevent caching of authenticated pages.

## Business-rule parity

Characterization tests are the specification for:

- 30-day investigation timing and acknowledged/first-meeting start rules;
- 60-day non-serious and 120-day serious investigation rules;
- day one counting, original 180-day confirmation changes, extensions, reasons, and history;
- per-person outcomes and escalation history;
- committee selection from the actual order;
- role/unit/case visibility and subject identity masking;
- redirects, validation failures, status codes, and audit details.

Rules are first extracted as input/output fixtures from synthetic databases, then implemented independently in the new domain layer. The replacement must not import or call legacy rule functions.

## Docker/WSL development

- Compose bind-mounts the working tree to `/app`, so PHP/Twig/CSS changes appear without rebuilding.
- A named Composer vendor volume prevents the bind mount from hiding dependencies.
- Composer manifest changes require `composer install` in the container; application source changes do not rebuild the image.
- The container serves `public/` through a development router on `127.0.0.1:8760` only.
- Cache/log/runtime paths are writable by the WSL UID/GID; synthetic database and demo credentials remain in ignored `runtime/`.

## IIS Server-A deployment

- Keep the application root at `C:\inetpub\wwwroot\exam\disc2`; configure the IIS application/document root to its `public` subdirectory so only public assets and `index.php` are web-accessible.
- Require PHP FastCGI compatible with the selected PHP/Symfony floor and enable PDO SQLite, cURL, mbstring, intl, and OPcache.
- `public/web.config` uses IIS URL Rewrite to send non-file requests to `index.php`, preserves the `/exam/disc2` base path, and denies sensitive extensions.
- External configuration remains at `C:\inetpub\private\disc2-config.php`; it supplies production secrets, database path, BMA integration, trusted proxies/hosts, and environment settings.
- `var/cache` and `var/log` are writable by the IIS application-pool identity; source/config/vendor remain read-only.

## Release artifact

Rewrite `tools/release.ps1` to build from a verified Git tag, run `composer install --no-dev --classmap-authoritative` in a clean staging directory, and package `bin`, `config`, `migrations`, `public`, `src`, `templates`, `vendor`, required tools, `composer.json`, and `composer.lock`. Exclude tests, development runtime, `.env` secrets, databases, logs, caches, and legacy pages.

The script validates the Symfony container, route table, production cache warmup, required extensions manifest, and absence of forbidden files before producing the ZIP and SHA-256. Server-A deployment remains operator-controlled and reversible.

## Build sequence

1. Freeze a route/form/schema/business-rule inventory and produce characterization fixtures from synthetic data.
2. Scaffold a fresh Symfony 7.4 LTS application and Docker/IIS runtime; no files from the deleted draft are reused.
3. Implement domain rules and authorization with unit tests.
4. Implement PDO repositories and migrations with integration tests.
5. Implement authentication/security and the shared Twig layout.
6. Implement complete read use cases and pages.
7. Implement complete write use cases, transactions, validation, audits, and redirects.
8. Add every legacy URL alias and run browser-level parity tests for each role.
9. Build and rehearse the IIS release artifact on a staging copy of the database and external config.
10. Cut over source, delete legacy entry points, deploy during a write freeze, migrate, smoke test, and reopen.

## Verification gates

- Unit: domain dates, access decisions, identity labels, validators.
- Integration: repositories, transactions, migrations, foreign keys, audit records.
- HTTP: anonymous/authenticated flows, CSRF, rate limits, all roles, forbidden reads/writes, redirects, legacy aliases, static-file denial.
- Browser: login, case list/detail, all workflow tabs, case creation/edit, committee and outcome flows, administration.
- Packaging: clean tag, deterministic dependency lock, no secrets/data/dev files, Symfony production cache warmup.
- IIS staging: subpath routing, FastCGI, session persistence, external config, file permissions, SQLite concurrency, rollback rehearsal.

## Acceptance and rollback

Cutover requires a green full suite, signed route/behavior checklist, successful staging rehearsal, verified backup, and operator approval.

- **Demo rollback:** stop writes, restore the prior code package plus the pre-cutover database backup/config snapshot, verify integrity, and reopen. Demo writes made after cutover may be discarded.
- **Production rollback:** stop writes, preserve and back up the current database, restore only the prior code package and compatible external config, run integrity/compatibility checks, and reopen. Every case change and audit entry made after cutover must remain. If staging proves the old release cannot read the migrated production schema, production cutover is blocked until compatibility is restored.

Database migrations for the first Symfony release must therefore be additive and backward-compatible. A destructive or semantically incompatible schema change requires a separate approved migration/cutover design with forward data recovery; it is outside this rewrite.

## Risks

- Big-bang parity gaps: mitigated by route inventory, characterization fixtures, role matrix, and staged cutover.
- IIS subpath/rewrite differences: mitigated by a real IIS staging rehearsal, not Docker-only tests.
- SQLite write contention: preserve busy timeout/WAL behavior and keep transactions short.
- Authentication drift: test the production BMA adapter against a non-production endpoint before cutover.
- Release size/dependency integrity: lock dependencies and build from a clean tag rather than copying a developer `vendor/` directory.
