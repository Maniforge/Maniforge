# Projects map — Maniforge Platform Core

**GitHub:** [`Maniforge/Maniforge`](https://github.com/Maniforge/Maniforge) — branch **`platform-core`**, tag **[`v0.1.1-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.1-box)**
**Server staging:** `79.174.90.4` — Caddy `:18090`, Go loopback `:8093–8097`, Postgres `:18096–18097`  
**Server path:** `/opt/maniforge/platform-core` — `verify-production: OK`  
**Clone (buyers):** `git clone --branch platform-core https://github.com/Maniforge/Maniforge.git`

## Layout profile

**Active profile:** `platform-core` (Go microservices only — production box v0.1)

## Layout

| Path | Role owner | Purpose |
|------|------------|---------|
| `cmd/rbac`, `cmd/tenant-licensing`, `cmd/manifest-engine`, `cmd/versioning`, `cmd/realtime` | backend | Platform core services |
| `cmd/migrate`, `cmd/preflight`, `cmd/manifest-journey` | backend | Ops + e2e smoke |
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
| Gateway (Caddy) | `:8080` | **`:18090`** (staging) or **`:443`** (+ `:80` ACME) |
| RBAC | `:8093` | **`127.0.0.1:8093`** loopback |
| Tenant Licensing | `:8094` | **`127.0.0.1:8094`** loopback |
| Manifest Engine | `:8095` | **`127.0.0.1:8095`** loopback |
| Versioning | `:8096` | **`127.0.0.1:8096`** loopback |
| Realtime | `:8097` | **`127.0.0.1:8097`** loopback |
| PostgreSQL primary | **`:5435`** | **`127.0.0.1:18096`** |
| PostgreSQL replica | N/A (local) | **`127.0.0.1:18097`** |

## Server deploy

| Item | Value |
|------|--------|
| Install root | `/opt/maniforge/platform-core` |
| Env | `deploy/.env.platform` (from `.env.platform.server.example`) |
| Install | `sudo bash deploy/scripts/install-production.sh` |
| Verify | `bash deploy/scripts/verify-production.sh` |

## Ownership defaults

- Go services, migrations → **backend** (`cmd/`, `internal/`, `migrations/pg/`)
- Compose, systemd, install scripts → **devops** (`deploy/`, `.github/`)
- Architecture docs → **tech-lead** (`docs/`, `kb/`)

**Collision rule:** one agent — one path-set per iteration. See `kb/ORCHESTRATION.md`.
