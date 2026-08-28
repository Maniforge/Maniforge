# Maniforge platform deploy

Local Docker stack (dev) and **server/prod** (nzgapp) are different paths.

## Local (dev)

One command: PostgreSQL + migrations + platform core **in Docker** + Caddy gateway.

```powershell
cd E:\Artem\maniforge_low_code_platform
copy deploy\.env.platform.example deploy\.env.platform
make platform-up
make platform-health
```

| Service | Host port | Health |
|---------|-----------|--------|
| Gateway (Caddy) | `8080` | `http://127.0.0.1:8080` |
| RBAC | `8093` | `/rbac/health` |
| Tenant Licensing | `8094` | `/tenant-licensing/health` |
| Manifest Engine | `8095` | `/health` |
| Versioning | `8096` | `/versioning/health` |
| Realtime | `8097` | `/health` |
| PostgreSQL | `5435` | `pg_isready` |

Compose: `deploy/compose.platform.yml`. Journeys from the host use `8093–8097` directly.

## Server / prod (nzgapp, `79.174.90.4`)

**Canonical repo:** https://github.com/Maniforge/Maniforge — branch `platform-core`, tag `v0.1.0-box` (public).

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
cp deploy/.env.platform.server.example deploy/.env.platform
# edit secrets in deploy/.env.platform — do NOT commit
```

**Fast path:** Postgres stays in Docker; **Go runs on the host** (systemd); **Caddy on the host** listens **:18090 only** (not 80/443).

| What | Where |
|------|--------|
| PostgreSQL primary + replica | Docker (`compose.platform.server.yml`) |
| rbac, tenant-licensing, manifest-engine, versioning, realtime | systemd + binaries in `/opt/maniforge/platform-core/bin/` |
| Gateway | host Caddy `:18090` → `127.0.0.1:8093–8097` |

Env: `/opt/maniforge/platform-core/deploy/.env.platform` (from `.env.platform.server.example`).

- `APP_URL` is **scheme + host only** (`http://79.174.90.4`). Gateway port is `MANIFORGE_GATEWAY_PORT=18090`.
- Go joins them at start (`joinPublicOrigin`) for invite links / OpenAPI. Do not put `:18090` inside `APP_URL`.
- Host Go uses `MANIFORGE_DB_HOST=127.0.0.1` and `MANIFORGE_DB_PORT=18096` (published primary). In-container Postgres is still `5432`.
- Journeys: `http://127.0.0.1:18090/rbac` or `http://79.174.90.4:18090/rbac`.

```bash
cd /opt/maniforge/platform-core
bash deploy/scripts/server-build.sh
bash deploy/scripts/server-up.sh
# restart one service:
systemctl restart maniforge-rbac
```

Health: `http://79.174.90.4:18090/rbac/health`

## Production box (sellable deploy)

**Spec:** [docs/PRODUCTION_BOX.md](../docs/PRODUCTION_BOX.md)

One-command install on clean Ubuntu 22.04/24.04 (source tree must exist first):

```bash
cd /opt/maniforge/platform-core
sudo bash deploy/scripts/install-production.sh --domain platform.customer.ru
```

Staging by IP (no TLS, port 18090):

```bash
sudo bash deploy/scripts/install-production.sh
```

Post-install verification:

```bash
bash deploy/scripts/verify-production.sh
cd /opt/maniforge/platform-core && make preflight
```

| Script | Purpose |
|--------|---------|
| `install-production.sh` | apt + docker + go + caddy, env secrets, build, migrate, systemd, health |
| `verify-production.sh` | systemd active, gateway health all services, Postgres replication |
| `server-build.sh` | Rebuild Go binaries only |
| `server-up.sh` | Postgres compose + migrate + restart (upgrade path) |

**TLS:** `deploy/Caddyfile.production` — template with `{domain}`; install renders `Caddyfile.active` and sets `MANIFORGE_CADDYFILE` in `.env.platform`.

**Env:** `deploy/.env.production.example` — production overrides (host + port vars, no duplicate service URLs).

## Commands (local Makefile)

```powershell
make platform-init      # copy deploy/.env.platform.example → deploy/.env.platform
make platform-up        # build + docker compose up -d
make platform-down      # остановить стек
make platform-logs      # логи всех сервисов
make platform-health    # curl health всех сервисов + gateway
make platform-migrate   # повторно прогнать миграции (one-shot)
```

## Production profile (hardening)

1. Copy `deploy/.env.production.example` keys into live `.env.platform` as needed
2. Set `APP_ENV=production`, tokens, PII key
3. See [MANIFORGE_ENTERPRISE_HARDENING.md](../docs/MANIFORGE_ENTERPRISE_HARDENING.md)

## Troubleshooting

**Port busy** — local: change mapping in `compose.platform.yml`. Server: Caddy must own **18090** only; do not bind 80/443 (other stacks).

**migrate failed** — server: `journalctl -u maniforge-rbac` / run `./bin/maniforge-migrate` from `/opt/maniforge/platform-core` with `.env.platform` sourced. Check `127.0.0.1:18096`.

**rbac-journey fails** — `curl http://127.0.0.1:18090/rbac/health` (server) or `http://127.0.0.1:8093/rbac/health` (local).

**Server journeys (nzgapp):**

```bash
cd /opt/maniforge/platform-core
make server-journey                    # gateway http://127.0.0.1:18090
make server-journey GATEWAY=http://79.174.90.4:18090
```

`server-manifest-journey` sources `deploy/.env.platform` (Postgres `18096`, etc.). `server-rbac-journey` hits services via Caddy, not loopback `:8093`.

**Sync from Windows** — tar/rsync tree to server (exclude `.git`, `node_modules`, `.env`, `deploy/.env.platform`). `install-production.sh` runs `fix_deploy_script_perms` (CRLF → LF, `chmod +x` on `deploy/postgres/*.sh`) after unpack.
