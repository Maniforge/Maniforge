# Maniforge Production Box

**Коммерческий пакет развёртывания** платформенного ядра: RBAC, Tenant Licensing, Manifest Engine, Versioning, Realtime. Прикладные модули (WMS, avtosbor, `.mfpack`) — отдельные фазы и лицензии.

**Аудитория:** sales engineering, DevOps покупателя, согласование COO.

**Релиз v0.1.2-box:** [github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge/tree/platform-core) · тег [`v0.1.2-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.2-box) · публичный репозиторий · ветка по умолчанию `platform-core`.

---

## Быстрый старт (копировать целиком)

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git
cd Maniforge
# или: git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
# cd /opt/maniforge/platform-core

cp deploy/.env.platform.server.example deploy/.env.platform
# отредактируйте секреты в deploy/.env.platform — не коммитьте этот файл

sudo bash deploy/scripts/install-maniforge.sh --skip-apt --non-interactive
bash deploy/scripts/verify-maniforge.sh
```

**Production с HTTPS** — после того как DNS A-record вашего FQDN указывает на сервер:

```bash
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com
bash deploy/scripts/verify-maniforge.sh
```

---

## Что получает покупатель

| Включено | Не включено (v0.1.2-box) |
|----------|--------------------------|
| 5 Go-сервисов platform core | App Store / `.mfpack` runtime |
| PostgreSQL 16 primary + streaming replica | Supply-chain modules (warehouses, WMS) |
| Caddy gateway (HTTPS по вашему FQDN или IP:18090 staging на вашем сервере) | Полный CI/CD pipeline заказчика |
| systemd restart policies | Managed SaaS multi-tenant hosting |
| Скрипты install / verify / upgrade | PHP reference stack |

**Compliance hooks:** шаблоны 152-ФЗ в `docs/legal/` и `docs/152FZ_COMPLIANCE.md` — заполнение и юридическое оформление на стороне покупателя.

---

## Минимальное железо

| Ресурс | Минимум | Рекомендуется (до 50 пользователей) |
|--------|---------|-------------------------------------|
| CPU | 2 vCPU | 4 vCPU |
| RAM | 4 GB | 8 GB |
| Disk | 40 GB SSD | 80 GB SSD (WAL archive + backups) |
| OS | Ubuntu 22.04 или 24.04 LTS | то же |
| Сеть | 80, 443 (HTTPS) или 18090 (staging без TLS на IP заказчика) | статический IP, DNS A-record на ваш FQDN |

---

## Установка на чистой Ubuntu

После клонирования репозитория на сервер:

```bash
# 1. Исходники в стандартный каталог
sudo mkdir -p /opt/maniforge
sudo git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
cd /opt/maniforge/platform-core

cp deploy/.env.platform.server.example deploy/.env.platform
# отредактируйте секреты в deploy/.env.platform — не коммитьте файл

# 2a. Production с HTTPS (ваш FQDN уже указывает на сервер)
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com

# 2b. Staging на вашем IP (без TLS, порт 18090) — до выдачи домена
# sudo bash deploy/scripts/install-maniforge.sh --skip-apt --non-interactive
```

Скрипт **идемпотентен**: повторный запуск безопасен (apt/docker/go/caddy, build, migrate, systemd, verify).

---

## Переменные окружения

| Файл | Назначение |
|------|------------|
| `deploy/.env.platform.server.example` | Базовый шаблон (host, ports, loopback addrs) |
| `deploy/.env.production.example` | Production overrides (без дублирующих URL сервисов) |
| `deploy/.env.platform` | **Live** файл на сервере (не в git) |

**Модель URL (обязательно):**

- `APP_URL` — только `scheme://host` (`https://platform.example.com`)
- `MANIFORGE_GATEWAY_PORT` — `443` (HTTPS) или `18090` (staging)
- `MANIFORGE_PUBLIC_HOST` — FQDN или IP для скриптов
- Публичный origin для journeys: `public_origin()` в `deploy/scripts/server-public-urls.sh`
- Внутренние hop'ы: `MANIFORGE_*_ADDR=127.0.0.1:8093–8097` (не дублируть HTTP URL в env)

`install-maniforge.sh --domain` генерирует секреты (`openssl`), выставляет `APP_ENV=production`, рендерит `Caddyfile.active`.

---

## Caddy / TLS

| Профиль | Файл | Порт |
|---------|------|------|
| Staging на IP заказчика | `deploy/Caddyfile.server` | `:18090` |
| Production (domain) | `deploy/Caddyfile.production` → `Caddyfile.active` | `:443` auto HTTPS |

Ручной рендер:

```bash
sed 's/{domain}/platform.example.com/g' deploy/Caddyfile.production > deploy/Caddyfile.active
# В .env.platform:
# MANIFORGE_CADDYFILE=/opt/maniforge/platform-core/deploy/Caddyfile.active
# APP_URL=https://platform.example.com
# MANIFORGE_GATEWAY_PORT=443
systemctl restart maniforge-caddy
```

Требования: DNS → сервер, порты 80/443 открыты.

---

## Post-install checklist

```bash
# 1. Автоматическая проверка (systemd + gateway + replication)
bash deploy/scripts/verify-maniforge.sh

# 2. Preflight (env + PostgreSQL guards)
cd /opt/maniforge/platform-core && make preflight

# 3. E2E smoke (рекомендуется перед приёмкой)
make manifest-journey

# 4. HTTPS acceptance
curl -sf https://platform.example.com/rbac/health
```

Ожидаемый результат verify: 6/6 systemd active, 4 health через gateway + realtime loopback, replica `streaming`.

---

## Backup / restore

**Backup (ежедневно):**

```bash
cd /opt/maniforge/platform-core
pg_dump -Fc -h 127.0.0.1 -p 18096 -U maniforge maniforge > /var/backups/maniforge-$(date +%F).dump
```

**Restore (runbook, тест на staging IP заказчика):**

```bash
systemctl stop maniforge-{rbac,tl,manifest,versioning,realtime,caddy}
docker compose -f deploy/compose.platform.server.yml stop postgres-replica
pg_restore -h 127.0.0.1 -p 18096 -U maniforge -d maniforge --clean /var/backups/maniforge-YYYY-MM-DD.dump
bash deploy/scripts/server-up.sh
bash deploy/scripts/verify-maniforge.sh
```

Полный RPO/RTO drill — backlog фазы C (`MANIFORGE_ENTERPRISE_HARDENING.md`).

---

## Upgrade path

```bash
cd /opt/maniforge/platform-core
git pull   # или rsync нового релиза
bash deploy/scripts/server-build.sh
bash deploy/scripts/server-up.sh
bash deploy/scripts/verify-maniforge.sh
make manifest-journey   # опционально, перед переключением трафика
```

---

## Scheduler (production)

Фоновые задачи включены в Phase C (systemd timers):

| Timer | Интервал | Бинарник |
|-------|----------|----------|
| `maniforge-tl-expire.timer` | 10 min | `bin/maniforge-tl-expire-licenses` |
| `maniforge-tl-dispatch.timer` | 2 min | `bin/maniforge-tl-dispatch-events` |
| `maniforge-siem-forward.timer` | 3 min | `bin/maniforge-siem-forward` (если `RBAC_SIEM_WEBHOOK_ENABLED=true`) |
| `maniforge-backup.timer` | daily 03:15 UTC | `deploy/scripts/backup-postgres.sh` |

Установка после build:

```bash
sudo bash deploy/scripts/install-scheduler.sh
systemctl list-timers 'maniforge-*'
```

Preflight + backup drill:

```bash
make preflight
make backup-drill
bash deploy/scripts/backup-postgres.sh   # ручной pg_dump
```

---

## Phase C checklist (enterprise hardening)

| # | Item | Status v0.1.2-box+ |
|---|------|-------------------|
| C1 | systemd scheduler (expire, dispatch, backup) | ✅ timers + install script |
| C2 | `make preflight` в verify-maniforge | ✅ |
| C3 | CI: build + migrate + preflight + backup-drill | ✅ ci-go.yml |
| C4 | Go platform-ops journey (access-state smoke) | ✅ `make platform-ops-journey` |
| C5 | TLS + domain (edge or direct Caddy) | ✅ `--domain` + опционально `--edge-proxy` |
| C6 | `APP_ENV=production` на сервере заказчика | ✅ `--domain` + `--edge-proxy` (gateway :18090) |
| C7 | Полный PHP rbac 50-step journey | ❌ out-of-box (PHP ref не в platform-core) |

**TLS с edge reverse proxy** (если :443 уже занят на сервере заказчика):

```bash
# 1. DNS A-record: platform.example.com → IP вашего сервера
# 2. На сервере:
sudo bash deploy/scripts/install-maniforge.sh --domain platform.example.com --edge-proxy --skip-apt --non-interactive
# 3. Append deploy/caddy/edge-platform.example.com.caddy to host edge Caddy — docs/DNS_PLATFORM.md
bash deploy/scripts/verify-maniforge.sh
curl -sf https://platform.example.com/rbac/health
```

---

## Observability

| Что | Где |
|-----|-----|
| Service logs | `journalctl -u maniforge-rbac -f` (и др.) |
| Caddy | `journalctl -u maniforge-caddy -f` |
| Postgres | `docker logs maniforge-pg-primary` |
| Health | `verify-maniforge.sh` |
| Metrics | backlog P5 — Prometheus endpoint |

Restart policy: `Restart=always` на всех `maniforge-*.service`.

---

## CI/CD (documented, not shipped)

Для internal releases рекомендуется:

1. GitHub Actions: `make preflight`, `make test`, `make backup-drill` (см. `ci-go.yml`)
2. Deploy job: rsync + `install-maniforge.sh --skip-apt --non-interactive`
3. Post-deploy: `verify-maniforge.sh` + `make server-journey`

---

## Support doc (1 page for buyer)

**Deploy Maniforge Platform Core**

1. Ubuntu 22.04/24.04, 4 GB RAM, DNS на **ваш** FQDN  
2. `git clone --branch platform-core` → `/opt/maniforge/platform-core`  
3. `cp deploy/.env.platform.server.example deploy/.env.platform` → отредактировать секреты  
4. `sudo bash deploy/scripts/install-maniforge.sh --domain YOUR_DOMAIN`  
5. `bash deploy/scripts/verify-maniforge.sh`  
6. Открыть `https://YOUR_DOMAIN/rbac/health` — JSON ok  
7. Admin onboarding: см. `docs/MANIFORGE_NEW_USER_WORKFLOW.md`  
8. Backup: ежедневный `pg_dump` (см. выше)  
9. Support: логи через `journalctl`, секреты только в `deploy/.env.platform`

---

## Связанные документы

- [PRODUCTION_PLAN.md](PRODUCTION_PLAN.md) — фазы A–D
- [MANIFORGE_ENTERPRISE_HARDENING.md](MANIFORGE_ENTERPRISE_HARDENING.md) — production profile
- [152FZ_COMPLIANCE.md](152FZ_COMPLIANCE.md) — ПДн (reference)
- [deploy/README.md](../deploy/README.md) — операционные команды

