# Maniforge Production Box

**Коммерческий пакет развёртывания** платформенного ядра: RBAC, Tenant Licensing, Manifest Engine, Versioning, Realtime. Прикладные модули (WMS, avtosbor, `.mfpack`) — отдельные фазы и лицензии.

**Аудитория:** sales engineering, DevOps покупателя, согласование COO.

**Релиз v0.1.0-box:** опубликован — [github.com/Maniforge/Maniforge](https://github.com/Maniforge/Maniforge/tree/platform-core) · тег [`v0.1.0-box`](https://github.com/Maniforge/Maniforge/releases/tag/v0.1.0-box) · публичный репозиторий · ветка по умолчанию `platform-core`.

---

## Быстрый старт (копировать целиком)

```bash
git clone --branch platform-core https://github.com/Maniforge/Maniforge.git
cd Maniforge
# или: git clone --branch platform-core https://github.com/Maniforge/Maniforge.git /opt/maniforge/platform-core
# cd /opt/maniforge/platform-core

cp deploy/.env.platform.server.example deploy/.env.platform
# отредактируйте секреты в deploy/.env.platform — не коммитьте этот файл

sudo bash deploy/scripts/install-production.sh --skip-apt --non-interactive
bash deploy/scripts/verify-production.sh
```

**Reference deployment:** staging `79.174.90.4:18090` — git deploy, `verify-production.sh` OK. Следующий этап эксплуатации: TLS по домену, RBAC journey 50/50.

---

## Что получает покупатель

| Включено | Не включено (v0.1.0-box) |
|----------|--------------------------|
| 5 Go-сервисов platform core | App Store / `.mfpack` runtime |
| PostgreSQL 16 primary + streaming replica | Supply-chain modules (warehouses, WMS) |
| Caddy gateway (HTTPS по домену или IP:18090 staging) | Полный CI/CD pipeline заказчика |
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
| Сеть | 80, 443 (HTTPS) или 18090 (staging) | статический IP, DNS A-record |

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

# 2a. Production с HTTPS (домен уже указывает на сервер)
sudo bash deploy/scripts/install-production.sh --domain platform.customer.ru

# 2b. Staging по IP (без TLS, порт 18090)
# sudo bash deploy/scripts/install-production.sh --skip-apt --non-interactive
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

- `APP_URL` — только `scheme://host` (`https://platform.customer.ru`)
- `MANIFORGE_GATEWAY_PORT` — `443` (HTTPS) или `18090` (staging)
- `MANIFORGE_PUBLIC_HOST` — FQDN или IP для скриптов
- Публичный origin для journeys: `public_origin()` в `deploy/scripts/server-public-urls.sh`
- Внутренние hop'ы: `MANIFORGE_*_ADDR=127.0.0.1:8093–8097` (не дублируть HTTP URL в env)

`install-production.sh --domain` генерирует секреты (`openssl`), выставляет `APP_ENV=production`, рендерит `Caddyfile.active`.

---

## Caddy / TLS

| Профиль | Файл | Порт |
|---------|------|------|
| Staging (IP) | `deploy/Caddyfile.server` | `:18090` |
| Production (domain) | `deploy/Caddyfile.production` → `Caddyfile.active` | `:443` auto HTTPS |

Ручной рендер:

```bash
sed 's/{domain}/platform.customer.ru/g' deploy/Caddyfile.production > deploy/Caddyfile.active
# В .env.platform:
# MANIFORGE_CADDYFILE=/opt/maniforge/platform-core/deploy/Caddyfile.active
# APP_URL=https://platform.customer.ru
# MANIFORGE_GATEWAY_PORT=443
systemctl restart maniforge-caddy
```

Требования: DNS → сервер, порты 80/443 открыты.

---

## Post-install checklist

```bash
# 1. Автоматическая проверка (systemd + gateway + replication)
bash deploy/scripts/verify-production.sh

# 2. Preflight (env + PostgreSQL guards)
cd /opt/maniforge/platform-core && make preflight

# 3. E2E smoke (рекомендуется перед приёмкой)
make rbac-journey
make manifest-journey

# 4. HTTPS в браузере
curl -sf https://platform.customer.ru/rbac/health
```

Ожидаемый результат verify: 6/6 systemd active, 4 health через gateway + realtime loopback, replica `streaming`.

---

## Backup / restore

**Backup (ежедневно):**

```bash
cd /opt/maniforge/platform-core
make backup-drill   # snapshot counters before dump
pg_dump -Fc -h 127.0.0.1 -p 18096 -U maniforge maniforge > /var/backups/maniforge-$(date +%F).dump
```

**Restore (runbook, тест на staging):**

```bash
systemctl stop maniforge-{rbac,tl,manifest,versioning,realtime,caddy}
docker compose -f deploy/compose.platform.server.yml stop postgres-replica
pg_restore -h 127.0.0.1 -p 18096 -U maniforge -d maniforge --clean /var/backups/maniforge-YYYY-MM-DD.dump
bash deploy/scripts/server-up.sh
bash deploy/scripts/verify-production.sh
```

Полный RPO/RTO drill — backlog фазы C (`MANIFORGE_ENTERPRISE_HARDENING.md`).

---

## Upgrade path

```bash
cd /opt/maniforge/platform-core
git pull   # или rsync нового релиза
bash deploy/scripts/server-build.sh
bash deploy/scripts/server-up.sh
bash deploy/scripts/verify-production.sh
make rbac-journey   # опционально, перед переключением трафика
```

---

## Scheduler (production)

Настроить **systemd timers** или cron на хосте (не входит в install v1):

| Job | Интервал | Команда |
|-----|----------|---------|
| TL expire licenses | 5–15 min | `php maniforge/tenant-licensing/tools/expire_licenses.php` |
| TL dispatch events | 1–5 min | `php maniforge/tenant-licensing/tools/dispatch_events.php` |
| SIEM forward | 1–5 min | `make siem-forward` |
| PD retention (152-ФЗ) | по регламенту | `php maniforge/rbac/tools/pd_retention_enforce.php` |

---

## Observability

| Что | Где |
|-----|-----|
| Service logs | `journalctl -u maniforge-rbac -f` (и др.) |
| Caddy | `journalctl -u maniforge-caddy -f` |
| Postgres | `docker logs maniforge-pg-primary` |
| Health | `verify-production.sh` |
| Metrics | backlog P5 — Prometheus endpoint |

Restart policy: `Restart=always` на всех `maniforge-*.service`.

---

## CI/CD (documented, not shipped)

Для internal releases рекомендуется:

1. GitHub Actions: `make preflight`, `make test`, `make rbac-journey` (уже частично в `ci-go.yml`)
2. Deploy job: rsync + `install-production.sh --skip-apt --non-interactive`
3. Post-deploy: `verify-production.sh` + smoke journey

---

## Support doc (1 page for buyer)

**Deploy Maniforge Platform Core**

1. Ubuntu 22.04/24.04, 4 GB RAM, DNS на ваш домен  
2. `git clone --branch platform-core` → `/opt/maniforge/platform-core`  
3. `cp deploy/.env.platform.server.example deploy/.env.platform` → отредактировать секреты  
4. `sudo bash deploy/scripts/install-production.sh --domain YOUR_DOMAIN`  
5. `bash deploy/scripts/verify-production.sh`  
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
