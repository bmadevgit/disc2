# DISC2 Symfony Big-Bang Rewrite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace DISC2 with a fresh Symfony 7.4 LTS application that preserves current behavior and data while running in Docker/WSL and IIS Server-A.

**Architecture:** Build a new layered application with pure domain rules, application use cases, PDO/SQLite repositories, Symfony Security/controllers, and Twig. Legacy PHP is reference/test oracle only and is removed at the single source-control cutover; no legacy runtime bridge is permitted.

**Tech Stack:** PHP 8.5 Docker / PHP >=8.2 IIS, Symfony 7.4 LTS, Twig, Symfony Security/Form/Validator/CSRF, PDO SQLite, PHPUnit, BrowserKit, PowerShell release packaging, IIS FastCGI/URL Rewrite

**Spec:** `docs/superpowers/specs/2026-11-24-symfony-big-bang-rewrite-design.md`

## Global Constraints

- The new application must not require or include any legacy root PHP entry point at runtime.
- Preserve the current SQLite schema, `uat_schema_migrations`, business deadlines, authorization scopes, identity masking, auditing, form field behavior, redirects, status codes, and meaningful `.php` URLs.
- Use synthetic data only in development; production config, database, secrets, caches, and logs stay outside Git and the release artifact.
- WSL source edits must appear in Docker without rebuilding; dependency changes use Composer inside the container.
- IIS remains at `C:\inetpub\wwwroot\exam\disc2` with its application/document root set to `public`.
- Cutover may invalidate sessions and CSRF tokens; users sign in again and reopen forms.
- Demo rollback may restore the pre-cutover database. Production rollback restores old code against the current database and must retain all post-cutover writes/audits.
- First-release schema changes must be additive and backward-compatible with the old production release.
- Do not visually redesign DISC2 and do not apply `huashu-design` to the application.

## Review Focus

- A user must never read a case outside their role/unit/ownership scope or see a hidden subject identity.
- Every write must enforce authentication, CSRF, per-case authorization, validation, a short transaction, and the matching audit record.
- Deadline calculations must preserve day-one and acknowledged/first-meeting rules for all 30/60/120/180-day paths.
- IIS subpath routing and `.php` aliases must neither expose source/config/data files nor lose query strings/redirect targets.
- Production rollback must work without restoring the database after new Symfony writes have occurred.

---

### Task 1: Freeze compatibility inventory and characterization harness

**Files:**
- Create: `docs/rewrite/route-contract.md`
- Create: `docs/rewrite/role-access-matrix.md`
- Create: `tests/Legacy/run.php`
- Create: `tests/Fixtures/legacy-workflows.php`
- Modify: `tools/init.php`

**Interfaces:**
- Consumes: current procedural endpoints and synthetic SQLite initializer
- Produces: route/status/redirect/form contract; role matrix; workflow fixtures used by all later tasks

- [ ] **Step 1: Write inventory assertions that fail until every public endpoint is classified**

Assert each root PHP entry point is listed as page, write endpoint, redirect alias, JSON endpoint, CLI-only file, or retired-410 route; include method, parameters, response, role, and replacement route name.

- [ ] **Step 2: Capture role and identity behavior**

Exercise anonymous, admin, executive, unit head, group head, case owner, unrelated user, and identity-hidden cases against a fresh synthetic database; record only deterministic expectations.

- [ ] **Step 3: Capture workflow fixtures**

Add fixtures for equal/different acknowledgement and first-meeting dates; 30/60/120-day tracks; 180-day confirmation; extension history; escalation; committee membership; and per-person results.

- [ ] **Step 4: Run characterization suite twice**

Run: `docker compose run --rm app php tests/Legacy/run.php` twice against separately initialized temporary databases. Expected: identical clean output; the runner is dependency-free because Symfony/PHPUnit does not exist until Task 2. Port these assertions into PHPUnit after Task 2 without changing expected values.

- [ ] **Step 5: Commit**

Commit the compatibility documents, fixtures, and tests without changing production behavior.

### Task 2: Scaffold fresh Symfony runtime and dual environments

**Files:**
- Create: `composer.json`, `composer.lock`, `bin/console`, `public/index.php`
- Create: `config/{bundles.php,routes.yaml,services.yaml,packages/*.yaml}`
- Create: `src/Kernel.php`, `templates/base.html.twig`
- Create: `public/web.config`, `tools/dev-router.php`
- Modify: `Dockerfile`, `compose.yaml`, `.dockerignore`, `.gitignore`, `README.md`
- Test: `tests/Smoke/KernelBootTest.php`

**Interfaces:**
- Consumes: PHP 8.2+ and Task 1 route contract
- Produces: bootable Symfony 7.4 kernel; service `app`; public document root; writable `var/` and `runtime/`

- [ ] **Step 1: Write failing kernel/environment smoke tests**

Assert `test` and `dev` kernels boot, production config compiles, SQLite/cURL/mbstring/intl exist, and the base URL works beneath `/exam/disc2`.

- [ ] **Step 2: Scaffold only locked Symfony 7.4 LTS dependencies**

Require FrameworkBundle, SecurityBundle, TwigBundle, Form, Validator, Csrf, Asset, Monolog, Runtime, PHPUnit Bridge, BrowserKit, and CssSelector; create a fresh kernel without copying the deleted draft.

- [ ] **Step 3: Implement Docker live-development runtime**

Bind-mount source to `/app`, mount `vendor` separately, run Composer as the WSL UID/GID, serve `public/`, and publish only `127.0.0.1:8760`.

- [ ] **Step 4: Implement IIS front-controller rules**

Create `public/web.config` for URL Rewrite/FastCGI subpath handling, static files, and denial of sensitive extensions; document the one-time IIS application root change to `public`.

- [ ] **Step 5: Verify both configurations**

Run Composer validation, Symfony lint/config/container/route commands, PHPUnit smoke test, Compose config, and an IIS configuration review fixture. Expected: all clean.

- [ ] **Step 6: Commit**

Commit the fresh runtime with no application feature implementation.

### Task 3: Implement domain rules and value objects

**Files:**
- Create: `src/Domain/Workflow/{TrackType,DeadlineInput,DeadlineResult,DeadlineCalculator}.php`
- Create: `src/Domain/Access/{AccessRole,CaseScope,SubjectIdentityPolicy}.php`
- Create: `src/Domain/CaseFile/*`
- Test: `tests/Unit/Domain/**/*Test.php`

**Interfaces:**
- Consumes: exact Task 1 workflow fixtures and role matrix
- Produces: framework-free deadline and access APIs consumed by services/security

- [ ] **Step 1: Write failing parameterized deadline tests**

Use every fixture from `tests/Fixtures/legacy-workflows.php`; assert start date, inclusive day-one deadline, extension deadline/history, and escalation state.

- [ ] **Step 2: Implement immutable workflow value objects and calculator**

Expose `DeadlineCalculator::calculate(DeadlineInput): DeadlineResult`; reject invalid/missing combinations explicitly.

- [ ] **Step 3: Write failing access/identity tests**

Assert the full role matrix for list/read/write/admin and whether real identity or reference code is visible.

- [ ] **Step 4: Implement access policies**

Keep policies independent of Symfony sessions and PDO; accept explicit actor, assignment, ownership, unit, and subject values.

- [ ] **Step 5: Run unit suite and mutation-style boundary cases**

Expected: all fixture cases and off-by-one dates pass with no warnings.

- [ ] **Step 6: Commit**

Commit domain code and tests.

### Task 4: Implement SQLite infrastructure and migration command

**Files:**
- Create: `src/Infrastructure/Persistence/{ConnectionFactory,TransactionManager}.php`
- Create: `src/Infrastructure/Persistence/Repository/*Repository.php`
- Create: `src/Command/MigrateCommand.php`
- Modify: `config/services.yaml`
- Test: `tests/Integration/Persistence/**/*Test.php`, `tests/Integration/MigrateCommandTest.php`

**Interfaces:**
- Consumes: existing schema/migrations and domain objects from Task 3
- Produces: repository interfaces/services and `app:migrate` CLI command

- [ ] **Step 1: Write failing fresh-database and existing-database tests**

Assert foreign keys, WAL, 5000ms busy timeout, current migration records, integrity checks, and idempotent migration execution.

- [ ] **Step 2: Implement connection and transaction services**

Select `runtime/disc2-dev.sqlite` in dev/test and configured external database path in production; never derive production mode from request SAPI.

- [ ] **Step 3: Implement repositories query-for-query against the current schema**

Cover users/login logs, cases/meta/owners, subjects, tracks, committees, extensions, findings/results, access assignments, and audits with parameterized SQL.

- [ ] **Step 4: Implement migration command**

Apply versioned SQL once, run foreign-key/integrity checks, and fail atomically; keep migrations compatible with the prior release.

- [ ] **Step 5: Run integration suite on fresh and copied synthetic fixtures**

Expected: identical records/scopes and no schema drift.

- [ ] **Step 6: Commit**

Commit infrastructure and migration command.

### Task 5: Implement authentication, sessions, CSRF, voters, and audit guardrails

**Files:**
- Create: `src/Security/{User,UserProvider,LoginAuthenticator,CaseVoter,SubjectIdentityVoter}.php`
- Create: `src/Application/Auth/*`, `src/Controller/{LoginController,LogoutController}.php`
- Create: `templates/security/login.html.twig`
- Modify: `config/packages/security.yaml`, `config/routes.yaml`
- Test: `tests/Functional/Security/**/*Test.php`

**Interfaces:**
- Consumes: Task 3 policies, Task 4 repositories, external production config
- Produces: authenticated actor/token and voter decisions used by every controller

- [ ] **Step 1: Write failing security-flow tests**

Cover demo credentials, BMA adapter contract, already-authenticated redirects, five failures/15-minute IP lock, logout, fresh-login cutover behavior, cookie flags/path, and no-cache headers.

- [ ] **Step 2: Implement authenticators and provider**

Keep production BMA secrets external; preserve login log fields and role/unit identity mapping.

- [ ] **Step 3: Write failing voter/CSRF tests**

Assert every Task 1 role/case combination and 419-equivalent invalid/expired form behavior.

- [ ] **Step 4: Implement voters and CSRF policy**

All later write controllers must call voter decisions before loading hidden identity or entering a transaction.

- [ ] **Step 5: Run security suite**

Expected: forbidden access reveals no case/subject details and output is clean.

- [ ] **Step 6: Commit**

Commit the security layer.

### Task 6: Implement read application services and pages

**Files:**
- Create: `src/Application/Query/*`
- Create: `src/Controller/{CaseListController,CaseViewController,AdminDashboardController,SubjectController,TrackController,DisciplineResultsController}.php`
- Create: `templates/{case,admin,subject,track,discipline}/*.html.twig`
- Create: `public/assets/{uat.css,workflow.js}`
- Modify: `config/routes.yaml`, `templates/base.html.twig`
- Test: `tests/Functional/Read/**/*Test.php`

**Interfaces:**
- Consumes: repositories, actor/voters, domain rules
- Produces: native Symfony GET routes and views for all current readable screens

- [ ] **Step 1: Write failing role-scoped page tests**

Assert record sets, filters, metrics, tabs, identity labels, dates, status badges, 403/404 behavior, and no-cache headers for every role.

- [ ] **Step 2: Implement query services and thin controllers**

Controllers accept typed request values, authorize, invoke one query service, and render Twig; no controller SQL.

- [ ] **Step 3: Port templates and assets without redesign**

Preserve Thai copy, current navbar/profile dropdown, forms' field names, responsive behavior, and accessibility semantics.

- [ ] **Step 4: Add canonical and `.php` GET aliases**

Implement all read paths in `docs/rewrite/route-contract.md`, including `i.php`, `edit.php`, `profile.php`, `users.php`, and `view_case.php` redirect behavior.

- [ ] **Step 5: Run read parity and browser smoke tests**

Expected: Task 1 visible data/status/redirect expectations match.

- [ ] **Step 6: Commit**

Commit read-only features.

### Task 7: Implement case, subject, and role write use cases

**Files:**
- Create: `src/Application/Command/{CreateCase,UpdateCase,SaveSubject,AssignRole}*`
- Create: `src/Controller/{CaseWriteController,SubjectWriteController,RoleController}.php`
- Create/Modify: relevant Twig forms
- Test: `tests/Functional/Write/{Case,Subject,Role}*Test.php`

**Interfaces:**
- Consumes: security guardrails, repositories, transaction manager
- Produces: compatible writes/redirects/audits for case, subject, and role operations

- [ ] **Step 1: Write failing happy/error/forbidden tests for each use case**

Assert exact validation, redirects, database changes, rollback on error, and one matching audit record; include cross-case ID tampering.

- [ ] **Step 2: Implement Symfony forms/validators and commands**

Keep existing POST field names and `.php` route aliases; commands own transaction boundaries.

- [ ] **Step 3: Verify production rollback compatibility**

Run the legacy read/query characterization harness against a database written by the new commands. Expected: old release can read every new record.

- [ ] **Step 4: Run focused and full write tests**

Expected: no partial data/audits and no unauthorized changes.

- [ ] **Step 5: Commit**

Commit case/subject/role write slices together only after their shared transaction contract passes.

### Task 8: Implement workflow, committee, extension, and outcome writes

**Files:**
- Create: `src/Application/Command/{SaveTrack,ConvertSeriousDeadline,AddExtension,CreateCommittee,SaveFinding,SaveDisciplineResult}*`
- Create: `src/Controller/{WorkflowController,CommitteeController,DisciplineWriteController}.php`
- Modify: workflow/discipline Twig templates and routes
- Test: `tests/Functional/Write/{Workflow,Committee,Discipline}*Test.php`

**Interfaces:**
- Consumes: Task 3 calculator/policies and Task 4 transaction/repositories
- Produces: all remaining state-changing behavior and audit/history parity

- [ ] **Step 1: Write failing fixture-driven workflow tests**

Assert all 30/60/120/180-day calculations, start-date branches, confirmation reason, extensions, previous-round history, committee membership, and per-person results.

- [ ] **Step 2: Implement commands and controllers**

Recalculate server-side; never trust posted start/deadline values. Authorize the case before reading or writing subject IDs.

- [ ] **Step 3: Test concurrency and transaction failure**

Assert SQLite busy handling, retry/error response, and no partial workflow/audit writes.

- [ ] **Step 4: Verify old-release read compatibility**

Run characterization queries against new workflow records. Expected: prior production code can render them for rollback.

- [ ] **Step 5: Run full domain/integration/functional suites**

Expected: all parity fixtures pass with pristine output.

- [ ] **Step 6: Commit**

Commit the remaining write workflows.

### Task 9: Complete route contract and remove legacy runtime

**Files:**
- Modify: `config/routes.yaml`, `public/web.config`, `tools/dev-router.php`
- Delete: legacy root PHP application files listed in `docs/rewrite/route-contract.md`
- Test: `tests/Functional/RouteContractTest.php`, `tests/Functional/ExposureTest.php`

**Interfaces:**
- Consumes: all native controllers from Tasks 5-8
- Produces: Symfony-only runtime with complete compatibility aliases

- [ ] **Step 1: Make route/exposure tests fail on every remaining legacy dependency**

Assert every contract URL resolves correctly and every helper/source/config/data path is denied; scan source for runtime includes of deleted files.

- [ ] **Step 2: Add missing aliases and 410 routes**

Implement exact method/status/redirect behavior for every Task 1 classification.

- [ ] **Step 3: Delete legacy entry points and helpers**

Retain only approved migrations/tools/assets; no `LegacyController`, forwarding include, or procedural runtime file.

- [ ] **Step 4: Run complete suite and static checks**

Run PHP lint, Composer validation/audit, Symfony lint/container/routes, PHPUnit, exposure scan, and Docker HTTP smoke tests.

- [ ] **Step 5: Commit**

Commit the single source cutover only when all tests are green.

### Task 10: Build deterministic IIS release and rollback tooling

**Files:**
- Rewrite: `tools/release.ps1`
- Create: `tools/verify-release.ps1`, `tools/iis-smoke.ps1`, `docs/IIS-DEPLOYMENT.md`
- Modify: `MAINTAINERS.md`, `README.md`
- Test: `tests/Release/ReleasePackageTest.php`

**Interfaces:**
- Consumes: signed/tagged Symfony-only tree and `composer.lock`
- Produces: production ZIP, SHA-256, manifest, deployment/rollback procedure

- [ ] **Step 1: Write failing artifact-content tests**

Assert required Symfony/vendor/public files exist and tests, databases, runtime, `.env`, secrets, caches, logs, legacy pages, and dev dependencies do not.

- [ ] **Step 2: Rewrite clean-tag packaging**

Stage `git archive`, run production Composer install with authoritative classmap, validate container/routes/cache warmup, write manifest, ZIP, and SHA-256.

- [ ] **Step 3: Implement IIS staging checks**

Verify PHP/extensions, app-pool permissions, external config/database paths, `/exam/disc2` routing, sessions, static assets, denied files, and CLI migration.

- [ ] **Step 4: Document separate demo and production rollback**

Demo may restore the pre-cutover database; production must back up and keep the current database while restoring old code, then prove the old release reads all new writes/audits.

- [ ] **Step 5: Build and inspect a release from a test tag**

Expected: deterministic content checks, production cache warmup, route checks, and SHA-256 succeed.

- [ ] **Step 6: Commit**

Commit release tooling and operator documentation.

### Task 11: Full rehearsal and cutover readiness

**Files:**
- Create: `docs/rewrite/cutover-checklist.md`
- Create: `docs/rewrite/parity-results.md`
- Modify: files only for defects found by rehearsal

**Interfaces:**
- Consumes: release artifact, synthetic staging database/config, old production package
- Produces: signed evidence that deployment and both rollback modes are executable

- [ ] **Step 1: Rehearse Docker/WSL from a clean clone**

Build, initialize, sign in, exercise every role and critical workflow, change a Twig/CSS/PHP file without rebuilding, and confirm live reload behavior.

- [ ] **Step 2: Rehearse IIS staging deployment**

Deploy beneath `/exam/disc2`, run migrations, force fresh login, execute browser/HTTP parity suite, and verify file-system exposure and permissions.

- [ ] **Step 3: Rehearse demo rollback**

Create post-cutover demo writes, restore old code plus pre-cutover database, and verify the expected loss is limited to those demo writes.

- [ ] **Step 4: Rehearse production rollback semantics**

Create post-cutover writes/audits, restore only old code while preserving the current database, and prove all records remain readable and intact.

- [ ] **Step 5: Run final verification**

Run all unit/integration/functional/browser/package checks, SQLite integrity/foreign keys, Composer audit, Symfony production validation, and route/exposure inventory. Expected: clean output.

- [ ] **Step 6: Commit readiness evidence**

Commit checklists/results without secrets or production data. Actual Server-A deployment remains an explicit operator action outside this implementation plan.
