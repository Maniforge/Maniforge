# Projects map — Maniforge Platform Core

**GitHub:** [`Maniforge/Maniforge`](https://github.com/Maniforge/Maniforge) — branch **`platform-core`**, tag **[`v0.1.2-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.2-box)**  
**Buyer model:** customer clones repo → `install-maniforge.sh --domain <customer-fqdn>` on **their** Ubuntu server. See `README.md`, `docs/PRODUCTION_BOX.md`.

## Internal — reference install / QA (not buyer path)

> **Internal — not part of Production Box buyer path.**

| Item | Value |
|------|--------|
| Reference server | `79.174.90.4` (`nzgapp`) — Maniforge QA only |
| Staging health | `http://79.174.90.4:18090/rbac/health` |
| Optional demo FQDN | `platform.maniforge.ru` (DNS pending) — internal demo, not buyer production |
| Install root on reference | `/opt/maniforge/platform-core` |

Details: `kb/PLATFORM_PRODUCTION.md`, `docs/DNS_PLATFORM.md`.

## Layout profile

**Active profile:** `platform-core` (Go microservices only — production box v0.1)

## Layout

| Path | Role owner | Purpose |
|------|------------|---------|
| `cmd/rbac`, `cmd/tenant-licensing`, `cmd/manifest-engine`, `cmd/versioning`, `cmd/realtime` | backend | Platform core services |
| `cmd/migrate`, `cmd/preflight`, `cmd/manifest-journey`, `cmd/platform-ops-journey` | backend | Ops + e2e smoke |
| `cmd/tl-expire-licenses`, `cmd/tl-dispatch-events`, `cmd/siem-forward`, `cmd/backup-drill` | backend | Scheduler / ops CLIs |
| `internal/` | backend | Shared Go libraries, handlers, domain logic |
| `migrations/pg/` | backend | PostgreSQL schema |
| `deploy/` | devops | Production box: compose, Caddy, systemd, install/verify |
| `docs/` | tech-lead | Production box, architecture, OpenAPI |
| `kb/` | tech-lead | Conventions, orchestration (internal crew) |

## Current stack

- **Runtime:** Go 1.25, Fiber, PostgreSQL 16
- **Deploy:** Docker Compose (Postgres) + systemd (Go) + Caddy gateway

## Platform services (Go)

| Service | Local Docker | Production (host) |
|---------|--------------|-------------------|
| Gateway (Caddy) | `:8080` | **`:18090`** (customer staging) or **`:443`** (+ `:80` ACME) |
| RBAC | `:8093` | **`127.0.0.1:8093`** loopback |
| Tenant Licensing | `:8094` | **`127.0.0.1:8094`** loopback |
| Manifest Engine | `:8095` | **`127.0.0.1:8095`** loopback |
| Versioning | `:8096` | **`127.0.0.1:8096`** loopback |
| Realtime | `:8097` | **`127.0.0.1:8097`** loopback |
| PostgreSQL primary | **`:5435`** | **`127.0.0.1:18096`** |
| PostgreSQL replica | N/A (local) | **`127.0.0.1:18097`** |

## Server deploy (buyer path)

| Item | Value |
|------|--------|
| Install root | `/opt/maniforge/platform-core` (or customer-chosen path) |
| Env | `deploy/.env.platform` (from `.env.platform.server.example`) |
| Install | `sudo bash deploy/scripts/install-maniforge.sh --domain <customer-fqdn>` |
| Staging (no TLS) | `install-maniforge.sh` without `--domain` → `http://<customer-ip>:18090` |
| Verify | `bash deploy/scripts/verify-maniforge.sh` (includes preflight) |
| Acceptance | `https://<customer-fqdn>/rbac/health` |
| Scheduler | `sudo bash deploy/scripts/install-scheduler.sh` (Phase C) |

**Status (2026-08-28):** Phase **C ~done** @ [`v0.1.2-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.2-box). Reference QA on `79.174.90.4` — verify OK. Internal gate: DNS `platform.maniforge.ru` → edge TLS (optional demo FQDN only).

## Ownership defaults

- Go services, migrations → **backend** (`cmd/`, `internal/`, `migrations/pg/`)
- Compose, systemd, install scripts → **devops** (`deploy/`, `.github/`)
- Architecture docs → **tech-lead** (`docs/`, `kb/`)

**Collision rule:** one agent — one path-set per iteration. See `kb/ORCHESTRATION.md`.
