# Conventions — Maniforge Platform Core

## Language & style

- **Go** is the production runtime for all platform core services.
- Small focused diffs; match existing patterns in `internal/` and `cmd/`.
- Comments for non-obvious business rules only.
- No secrets in git — use `.env.example`, `deploy/.env.platform.server.example`.

## API contract

- JSON over HTTP; Fiber handlers use shared `httpx` helpers.
- RBAC prefix: `/rbac/api/v1/…`
- Manifest data: `/api/data/:entity`
- Tenant Licensing: `/tenant-licensing/api/v1/…`
- Errors: `{ "ok": false, "error": "…" }` (see existing handlers).

## Ports / URLs

### Local dev (host)

| What | URL |
|------|-----|
| RBAC | `http://127.0.0.1:8093/rbac` |
| Tenant Licensing | `http://127.0.0.1:8094/tenant-licensing` |
| Manifest Engine | `http://127.0.0.1:8095` |
| Versioning | `http://127.0.0.1:8096/versioning` |
| Realtime WS | `ws://127.0.0.1:8097` |

### Production staging (`79.174.90.4`)

`APP_URL` is scheme+host only (`http://79.174.90.4`). Gateway port is `MANIFORGE_GATEWAY_PORT=18090`. Paths: `/rbac`, `/tenant-licensing`, `/versioning`, `/ws`.  
Postgres in Docker (host `127.0.0.1:18096` / `18097`). Go on host loopback `127.0.0.1:8093–8097`. Caddy on host **:18090** only.

Env template: `deploy/.env.platform.server.example`

## Local start

```bash
docker compose up -d postgres
make deps build migrate

# Manual services (separate terminals)
make run-tl
make run-rbac

# E2E smoke (Go)
make manifest-journey
```

## Server start

```bash
cd /opt/maniforge/platform-core
bash deploy/scripts/server-build.sh
bash deploy/scripts/server-up.sh
systemctl restart maniforge-rbac   # one service
```

## Verification checklist

- `make health` or curl `/rbac/health` on active base URL
- `make manifest-journey` (Go e2e)
- `bash deploy/scripts/verify-production.sh` on server
- Replication: `SELECT * FROM pg_stat_replication` on primary

## Git & deploy

- Platform repo (canonical): `Maniforge/Maniforge` branch `platform-core`
- Lab / R&D: `Maniforge/maniforge_low_code_platform`
- Server path: `/opt/maniforge/platform-core`
