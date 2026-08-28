# Maniforge — deploy

Операционная документация по развёртыванию платформы. Два контура: **production server** (покупатель / on-premise) и **local dev** (разработка).

**Спецификация Production Box:** [docs/PRODUCTION_BOX.md](../docs/PRODUCTION_BOX.md) · релиз [`v0.1.2-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.2-box)

---

## Production server (on-premise)

**Репозиторий:** [github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge) — ветка `platform-core`.

### Установка (greenfield)

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
cd /opt/maniforge/platform-core

cp deploy/.env.platform.server.example deploy/.env.platform
# отредактируйте секреты в deploy/.env.platform — не коммитьте файл

sudo bash deploy/scripts/install-maniforge.sh --skip-apt --non-interactive
bash deploy/scripts/verify-maniforge
```


**HTTPS when :443 is already used (edge reverse proxy):**

```bash
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com --edge-proxy --skip-apt --non-interactive
# Append deploy/caddy/edge-platform.example.com.caddy to host edge Caddy — docs/DNS_PLATFORM.md
```
**С HTTPS** (DNS A-record вашего FQDN указывает на **ваш** сервер):

```bash
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com
bash deploy/scripts/verify-maniforge
```

**Staging на вашем сервере** (без TLS, порт 18090 — опция до выдачи домена):

```bash
sudo bash deploy/scripts/install-maniforge.sh
```

### Архитектура на сервере

| Компонент | Где работает |
|-----------|--------------|
| PostgreSQL primary + replica | Docker (`compose.platform.server.yml`) |
| rbac, tenant-licensing, manifest-engine, versioning, realtime | systemd + бинарники в `/opt/maniforge/platform-core/bin/` |
| Gateway | host Caddy `:443` (production) или `:18090` (staging на IP заказчика) → `127.0.0.1:8093–8097` |

Env: `/opt/maniforge/platform-core/deploy/.env.platform` (из `.env.platform.server.example`).

**Модель URL:**

- `APP_URL` — только `scheme://host` (`https://platform.example.com` или `http://203.0.113.10`)
- `MANIFORGE_GATEWAY_PORT` — `443` (HTTPS) или `18090` (staging без TLS)
- Go собирает публичный origin при старте (`joinPublicOrigin`) — не добавляйте порт в `APP_URL`
- Postgres на хосте: `MANIFORGE_DB_HOST=127.0.0.1`, `MANIFORGE_DB_PORT=18096`

### Health-check

| Профиль | URL |
|---------|-----|
| Production (HTTPS) | `https://<customer-fqdn>/rbac/health` |
| Staging на IP заказчика | `http://<ваш-IP>:18090/rbac/health` |

### Обслуживание

```bash
cd /opt/maniforge/platform-core
bash deploy/scripts/server-build.sh      # пересборка Go
bash deploy/scripts/server-up.sh         # postgres + migrate + restart
systemctl restart maniforge-rbac         # один сервис
bash deploy/scripts/verify-maniforge
```

| Скрипт | Назначение |
|--------|------------|
| `install-maniforge.sh` | apt + docker + go + caddy, env, build, migrate, systemd, health |
| `verify-maniforge` | systemd active, gateway health, Postgres replication |
| `server-build.sh` | Пересборка Go-бинарников |
| `server-up.sh` | Postgres compose + migrate + restart (upgrade path) |

**TLS:** `deploy/Caddyfile.production` — шаблон с `{domain}`; install рендерит `Caddyfile.active` и выставляет `MANIFORGE_CADDYFILE` в `.env.platform`.

---

## Local dev (Docker)

Локальный стек для разработки: PostgreSQL + platform core в Docker + Caddy gateway.

```bash
cp deploy/.env.platform.example deploy/.env.platform
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

Compose: `deploy/compose.platform.yml`. Journeys с хоста — напрямую на `8093–8097`.

### Makefile (local)

```bash
make platform-init      # copy deploy/.env.platform.example → deploy/.env.platform
make platform-up        # build + docker compose up -d
make platform-down      # остановить стек
make platform-logs      # логи всех сервисов
make platform-health    # curl health всех сервисов + gateway
make platform-migrate   # повторно прогнать миграции (one-shot)
```

---

## Production profile (hardening)

1. Скопируйте ключи из `deploy/.env.production.example` в live `.env.platform`
2. Установите `APP_ENV=production`, токены, PII key
3. См. [MANIFORGE_ENTERPRISE_HARDENING.md](../docs/MANIFORGE_ENTERPRISE_HARDENING.md)

---

## Troubleshooting

**Port busy** — local: измените mapping в `compose.platform.yml`. Server: Caddy владеет `443` (production) или `18090` (staging без TLS на вашем IP).

**migrate failed** — `journalctl -u maniforge-rbac`; запустите `./bin/maniforge-migrate` из `/opt/maniforge/platform-core` с sourced `.env.platform`. Проверьте `127.0.0.1:18096`.

**manifest-journey fails** — `curl http://127.0.0.1:18090/rbac/health` (server) или `http://127.0.0.1:8093/rbac/health` (local); затем `make server-journey`.

**Server journeys:**

```bash
cd /opt/maniforge/platform-core
make server-journey
make server-journey GATEWAY=http://<IP>:18090
```

**Sync с рабочей станции** — rsync дерева на сервер (исключить `.git`, `node_modules`, `.env`, `deploy/.env.platform`). `install-maniforge.sh` выполняет `fix_deploy_script_perms` (CRLF → LF, `chmod +x`).

