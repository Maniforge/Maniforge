# Projects map — Maniforge low-code platform

**Canonical GitHub:** [`Maniforge/Maniforge`](https://github.com/Maniforge/Maniforge) — branch **`platform-core`**, tag **[`v0.1.0-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box)** (public, default branch)  
**Lab remote:** [`Maniforge/maniforge_low_code_platform`](https://github.com/Maniforge/maniforge_low_code_platform) `main` @ `8ded974` — sync with platform-core; archive when COO ready  
**Server staging:** `79.174.90.4` (nzgapp) — Caddy `:18090`, Go loopback `:8093–8097`, Postgres `:18096–18097`  
**Server path:** `/opt/maniforge/platform-core` (systemd + `bin/`; **tarball today** — next: `git clone --branch platform-core`)  
**Clone (buyers):** `git clone --branch platform-core https://github.com/Maniforge/Maniforge.git`  
**Not this repo:** `Maniforge-Enterprise`, `wms.svitex.online/platform/` (draft), `agent-crew` (orchestration pack only)

## Layout profile

**Active profile:** `custom` (Go microservices + PHP reference + Vite frontends)

## Layout

| Path | Role owner | Purpose |
|------|------------|---------|
| `cmd/` | backend | Go service entrypoints (rbac, tenant-licensing, manifest-engine, …) |
| `internal/` | backend | Shared Go libraries, handlers, domain logic |
| `migrations/pg/` | backend | PostgreSQL schema (Go runtime) |
| `maniforge/`, `app/Maniforge/` | backend | PHP reference + journey tests (contract) |
| `frontend/apps/admin` | frontend | Admin UI (Vite/React) |
| `frontend/apps/scanner` | frontend | WMS scanner UI (Vite) |
| `public/`, `templates/` | frontend | Static / PHP-rendered pages |
| `deploy/` | devops | Docker compose, Caddy, server profiles |
| `docs/` | tech-lead | Architecture, ADR, production plan |
| `kb/` | tech-lead | Orchestration, conventions, ADRs |
| `.cursor/agents/` | — | Maniforge crew roles |
| `.cursor/commands/` | — | `/maniforge` |

## Current stack

- **Runtime (prod path):** Go 1.25, Fiber, PostgreSQL 16
- **Reference:** PHP 8.x + MySQL journeys
- **Web:** Vite + React (admin), Vite (scanner)
- **Deploy:** Docker Compose (`deploy/compose.platform.server.yml` on nzgapp)

## Platform services (Go)

| Service | Local Docker | nzgapp (fast path: Go on host) |
|---------|--------------|--------------------------------|
| Gateway (Caddy) | `:8080` | **`:18090`** (staging) or **`:443`** (+ `:80` ACME) |
| RBAC | `:8093` | **`127.0.0.1:8093`** loopback — Caddy only |
| Tenant Licensing | `:8094` | **`127.0.0.1:8094`** loopback |
| Manifest Engine | `:8095` | **`127.0.0.1:8095`** loopback |
| Versioning | `:8096` | **`127.0.0.1:8096`** loopback |
| Realtime | `:8097` | **`127.0.0.1:8097`** loopback |
| PostgreSQL primary | **`:5435`** (host → container `:5432`) | **`127.0.0.1:18096`** (Docker publish) |
| PostgreSQL replica | **N/A** (local compose — single DB) | **`127.0.0.1:18097`** (Docker publish) |

Go services are **not** published as `18091–18095`; Caddy reverse-proxies to loopback `8093–8097`.

## Server deploy (nzgapp)

| Item | Value |
|------|--------|
| Install root | `/opt/maniforge/platform-core` |
| Env | `deploy/.env.platform` (from `.env.platform.server.example`) |
| Postgres | Docker — `deploy/compose.platform.server.yml` |
| Go services | systemd — `deploy/systemd/maniforge-*.service` |
| Gateway | host Caddy — `deploy/Caddyfile.server` (:18090) or `Caddyfile.production` (:443) |
| Health | `http://79.174.90.4:18090/rbac/health` |

**Status (2026-08-28):** **v0.1.0-box published** (public GitHub). Staging `verify-production: OK` on `:18090`. **Remaining:** server `git clone` cutover; TLS domain; RBAC journey 50/50.

**Canonical one-command install:** `sudo bash deploy/scripts/install-production.sh` (see `docs/PRODUCTION_BOX.md`).  
**Verify:** `bash deploy/scripts/verify-production.sh`

**Next gap:** nzgapp host still on tarball — switch to `git clone --branch platform-core` + re-run install/verify (see `docs/REPO_STRATEGY.md`).

## Ownership defaults

- Go services, migrations, internal packages → **backend** (`cmd/`, `internal/`, `migrations/pg/`)
- Admin/scanner UI, `public/` assets → **frontend** (`frontend/`, `public/`)
- Compose, Dockerfiles, env examples, server scripts → **devops** (`deploy/`)
- ADRs, architecture docs, ORCHESTRATION → **tech-lead** (`docs/`, `kb/`)
- PHP journeys — backend owns; change only for contract parity with Go
- Product briefs / landing copy → product-manager / designer (docs only)

## Agent roles

| Role | When | Default paths |
|------|------|---------------|
| backend | Go/PHP API, migrations | `cmd/`, `internal/`, `migrations/`, `maniforge/` |
| frontend | UI | `frontend/`, `public/`, `templates/` |
| devops | Compose, CI, server | `deploy/`, `docker-compose.yml`, `.github/` |
| qa | After implementers | `make *-journey`, health checks |
| product-manager | Positioning, UX briefs | `docs/`, `kb/` (no `cmd/`) |

**Collision rule:** one agent — one path-set per iteration. See `kb/ORCHESTRATION.md`.

## Priority (2026-08)

**Next vertical slice (before enterprise sell):** **v0.1.0-box** — commit `deploy/` → push `Maniforge/Maniforge` `platform-core` → tag → server `git clone` + verify. Manifest: `docs/V0.1_BOX_MANIFEST.md`.

1. **Phase A→sell** — ~~v0.1.0-box in GitHub~~ **done** (`8ded974`, public). Server git cutover **open**
2. **Phase C** — TLS/domain, RBAC journey 50/50, scheduler, CI, backup drill
3. **Phase B** — supply chain (warehouses → products → inventory → wms)
4. **Phase D** — `.mfpack` module packages
5. **Then** — avtosbor / devent as modules (not in this repo's prod path yet)
