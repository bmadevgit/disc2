# Docker on WSL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run the DISC2 synthetic-data development environment through Docker Compose on WSL at `http://127.0.0.1:8760/`.

**Architecture:** A PHP 8.5 CLI image supplies PDO SQLite and cURL. Compose bind-mounts the working tree, publishes loopback only, and runs the existing development router with an explicit Docker-development flag.

**Tech Stack:** Docker, Docker Compose, PHP 8.5 CLI, PDO SQLite, cURL

**Spec:** `docs/superpowers/specs/2026-11-24-docker-wsl-design.md`

## Global Constraints

- Use synthetic data only and keep `runtime/` outside the image.
- Publish only `127.0.0.1:8760:8760`.
- Reuse `php tools/init.php` and `tools/router.php`.
- Do not add production deployment behavior.

## Review Focus

- A normal non-Docker remote address must remain denied by the development router.
- Docker access must require both `DISC2_DEV=1` and `DISC2_DOCKER=1`.
- Missing runtime data must produce the existing initialization flow, not baked-in data.
- The image must contain both `pdo_sqlite` and `curl`.
- Compose must not publish on `0.0.0.0`.

---

### Task 1: Containerized development runtime

**Files:**
- Create: `Dockerfile`
- Create: `compose.yaml`
- Create: `.dockerignore`
- Modify: `tools/router.php`
- Test: shell checks plus container HTTP smoke test

**Interfaces:**
- Consumes: existing `tools/init.php`, `tools/router.php`, and port `8760`
- Produces: Compose service `app`; environment flags `DISC2_DEV=1` and `DISC2_DOCKER=1`

- [ ] **Step 1: Record failing configuration checks**

Check that `Dockerfile`, `compose.yaml`, loopback publication, required PHP extensions, and Docker router gating do not yet exist.

- [ ] **Step 2: Add minimal Docker configuration and router gate**

Create a PHP 8.5 CLI image with `pdo_sqlite` and `curl`; bind-mount the source; run `php -S 0.0.0.0:8760 tools/router.php`; allow private Docker clients only with both explicit flags.

- [ ] **Step 3: Verify configuration and PHP syntax**

Run Compose config, build the image, and lint `tools/router.php`. Expected: all pass and port binding resolves to host IP `127.0.0.1`.

- [ ] **Step 4: Verify initialization and HTTP behavior**

Run `docker compose run --rm app php tools/init.php`, start the service, request `/`, and verify HTTP 200. Verify a disallowed router environment/request remains 404.

- [ ] **Step 5: Commit**

Commit the container runtime and router changes together after verification.

### Task 2: WSL usage documentation

**Files:**
- Modify: `README.md`

**Interfaces:**
- Consumes: Compose service `app` from Task 1
- Produces: copyable WSL build, init, start, credentials, and stop instructions

- [ ] **Step 1: Add the WSL Docker workflow**

Document prerequisites and exact Compose commands, including the one-time synthetic database initialization and loopback URL.

- [ ] **Step 2: Validate every documented command**

Run each command in order and confirm the page responds at `http://127.0.0.1:8760/`.

- [ ] **Step 3: Review secret and network safety**

Confirm `runtime/` remains ignored, is not in image context, and Compose exposes no non-loopback host port.

- [ ] **Step 4: Commit**

Commit the README instructions after validation.
