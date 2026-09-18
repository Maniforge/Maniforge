# Maniforge — миграция на Go

Go — **основной** контур. PHP (`app/Maniforge/*`, `maniforge/*`) — референс с паритетом API.

Обзор продукта: [MANIFORGE_PLATFORM_OVERVIEW.md](MANIFORGE_PLATFORM_OVERVIEW.md).

Go-модуль: `maniforge` (импорты `maniforge/internal/...`).

**Архитектура (согласованные границы):** [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md) + `docs/adr/`.  
**Карта всех Go-файлов:** [MANIFORGE_GO_CODEMAP.md](MANIFORGE_GO_CODEMAP.md) — обязательна к обновлению при добавлении файлов.  
**Правила Cursor:** `.cursor/rules/maniforge-architecture.mdc`, `go-file-documentation.mdc`.

## Структура репозитория

```text
cmd/
  rbac/main.go              # :8093, prefix /rbac
  tenant-licensing/main.go  # :8094, prefix /tenant-licensing
internal/
  config/                   # .env (как config/bootstrap.php)
  db/                       # PostgreSQL (pgx)
  platform/                 # httpx, middleware
  rbac/                     # handlers, services (порт с PHP)
  tenantlicensing/
bin/                        # go build output
```

PHP-контур **не удаляется** — journey-тесты в `maniforge/rbac/tools/` задают контракт.

## База данных

| Контур | СУБД | Миграции |
|--------|------|----------|
| **Go (основной)** | **PostgreSQL 15+** | `migrations/pg/*.sql`, `make migrate` |
| **PHP (референс)** | MySQL InnoDB | `maniforge/rbac/migrations/*.sql` |

Go и PHP используют **разные БД** до полного cutover. Схемы синхронизируются по контрактам таблиц, не по общему дампу.

## Запуск

```bash
# Go 1.24+ (или ~/.local/go/bin/go после установки в home)
docker compose up -d postgres   # или свой PostgreSQL 15+
make deps
make build
make migrate

# Терминал 1 — RBAC Go
make run-rbac
# → http://127.0.0.1:8093/rbac/health

# Терминал 2 — Tenant Licensing Go
make run-tl
# → http://127.0.0.1:8094/tenant-licensing/health

# PHP-референс (параллельно, другой порт)
php -S 127.0.0.1:8092 -t public public/index.php
# → http://127.0.0.1:8092/rbac/health
```

Переменные в `.env` (см. `.env.example`):

```dotenv
MANIFORGE_DB_HOST=127.0.0.1
MANIFORGE_DB_PORT=5433
MANIFORGE_DB_NAME=maniforge
MANIFORGE_DB_USER=maniforge
MANIFORGE_DB_PASS=maniforge
MANIFORGE_RBAC_ADDR=:8093
MANIFORGE_TENANT_LICENSING_ADDR=:8094
```

## Порядок портирования (MVP)

| # | Сервис | Endpoints | Статус |
|---|--------|-----------|--------|
| ✅ 0 | Скелет | `GET /health` | Go Fiber + PostgreSQL |
| ✅ 1a | Tenant Licensing | `GET /internal/v1/.../access-state` | Портировано |
| ✅ 1b | Tenant Licensing | `GET /api/v1/tenants`, `/plans`, `/entitlements` | Портировано (read) |
| ✅ 1c | Tenant Licensing | `GET /internal/v1/events/pending`, `POST .../ack` | Портировано |
| ✅ 2a | RBAC auth | `POST /api/v1/auth/login`, `GET /api/v1/me` | MVP (phone + argon2id) |
| ✅ 2b | RBAC licensing | `licensingclient` HTTP + in-process fallback | Как PHP |
| ✅ 2c | RBAC session | `POST /api/v1/auth/refresh`, `POST /api/v1/auth/logout` | Ротация refresh + revoke |
| ✅ 3 | RBAC register | `POST /api/v1/auth/register` | Self-reg + invite (без attach-existing) |
| ✅ 3b | RBAC user/profile | `maniforge_users` + `maniforge_user_profile` | Критичные поля → `security_version` + revoke all sessions |
| ✅ 3c | RBAC profile API | `PATCH /me/profile`, `PATCH /me/identity`, `POST /me/change-password` | Профиль без logout; identity/password с logout |
| ✅ 4a | RBAC reauth + CSRF | `POST /auth/reauth`, `X-CSRF-Token`, `X-Action-Token` | Journey |
| ✅ 4b | RBAC me extended | `/me/profile`, `/me/permissions`, `/me/contexts`, `/me/access`, `/me/console-access` | Journey |
| ✅ 4c | RBAC projects | `GET/POST /projects`, `POST /global-variables` | Journey |
| ✅ 4d | RBAC admin invites | `POST /admin/registration-invites` | Journey |
| ✅ 4e | Privacy notice | `GET /privacy/notice` | Journey |
| ✅ 5 | Versioning HTTP | `:8096` `/api/v1/changes`, `/registry` | Journey |
| 6 | RBAC internal | `POST /internal/v1/tenant-events` | Бэклог |
| 7 | RBAC admin full | `admin/users`, roles, sessions, audit | Бэклог |

| ✅ 4 | Manifest Engine | manifests + data CRUD, field-path, OpenAPI JSON | MVP Go :8095 |
| ✅ 4b | Manifest Engine фазы 1–2 | licensing gate, audit log, DELETE manifest, field RBAC | `make manifest-journey` |
| ✅ 4c | Manifest Engine фаза 3 | versioning hook → `maniforge_ver_changes` | `make manifest-journey` (step versioning) |
| ✅ 4d | Manifest Engine фаза 4 | JSONB filter, pagination meta, OpenAPI YAML | `make manifest-journey` |
| ✅ 4e | Manifest Engine фаза 5 | Refine scaffold + `/refine-manifest` UI | `make manifest-refine-gen` |
| ✅ 4f | Manifest Engine фаза 6 | presets product/stock + seed | `make manifest-journey` |
| ✅ 6a | delegation / switch-context | `POST /auth/switch-context`, `/me/contexts` delegated | `make rbac-delegation-journey` |
| ✅ 6b | warehouses (WMS stocks) | Go :8098, projects `warehouse_id` | `make warehouses-journey` |
| 6 | supply chain (inventory, wms, products) | PHP → Go | Бэклог |

## Критерии приёмки MVP

```bash
php maniforge/rbac/tools/new_user_http_journey.php   # с Go base URL
php maniforge/rbac/tools/http_smoke.php
php maniforge/tenant-licensing/tools/http_smoke.php
```

Паритет JSON и HTTP-кодов — 1:1 с `docs/MANIFORGE_RBAC_OPENAPI.yaml`.

## Следующий шаг

1. ✅ Admin API (`users`, `user-roles`, `policies`, `ops-summary`, `sessions`, `audit`, `roles`, PD admin) и **`POST /internal/v1/tenant-events`** — `make rbac-admin-journey`, `make rbac-security-journey`.
2. ✅ **`platform_ops_journey`** — Go tenant-licensing write (PATCH tenant/subtenant, events) — `make rbac-platform-ops-journey`.
3. ✅ **Delegation** — `maniforge_tl_tenant_grants`, `/me/contexts` delegated, `POST /auth/switch-context` — `make rbac-delegation-journey`.
4. ✅ Warehouses — `make warehouses-journey` (`./bin/maniforge-warehouses` :8098).
5. Supply chain (inventory, wms, products) → следующие journey.

Проверка Manifest Engine:

```bash
make migrate && make build
# в отдельных терминалах: make run-tl, make run-rbac, make run-manifest
make manifest-journey
```
