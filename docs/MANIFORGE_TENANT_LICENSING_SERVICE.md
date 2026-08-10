# Maniforge Tenant/Licensing Service

## Назначение

Tenant/Licensing Service — отдельный платформенный контур для управления tenant lifecycle, subtenant registry (workspace / реферальные клиенты), тарифными планами, лицензиями, квотами и lifecycle events. Maniforge RBAC остается владельцем auth/RBAC/session, но проверяет runtime-доступ по оси **tenant + project** через internal access-state контракт.

Лицензия привязана к **tenant** (коммерция). **Project** — контур работ пользователя. Поле `subtenant_id` в RBAC — технический workspace, не managed client и не project.

## Runtime Contract

RBAC вызывает (основной путь):

```http
GET /internal/v1/tenants/{tenantCode}/projects/{projectCode}/access-state?workspace={workspaceSubtenant}
Authorization: Bearer <TENANT_LICENSING_INTERNAL_TOKEN>
```

`workspace` опционален: сужает поиск проекта, если `code` не уникален в tenant.

Legacy (deprecated, → project `main` в workspace):

```http
GET /internal/v1/tenants/{tenantCode}/subtenants/{subtenantCode}/access-state
```

Ответ:

```json
{
  "ok": true,
  "tenant_code": "default",
  "project_code": "main",
  "workspace_id": "",
  "tenant_active": true,
  "project_active": true,
  "license_active": true,
  "features": {"rbac": true, "admin_api": true},
  "limits": {"max_users": 100, "max_sessions": 500},
  "license": {
    "plan_code": "starter",
    "status": "active",
    "expires_at": null,
    "seats_max": null
  }
}
```

RBAC использует `licensingclient.AssertAccess(tenant, project, workspace)` в login, refresh и session authentication. При `tenant_active=false`, `project_active=false` или `license_active=false` новые сессии не выдаются, а существующие hard-deny сессии отзываются.

## Admin API

Все admin endpoints защищаются `TENANT_LICENSING_ADMIN_TOKEN`, если он задан:

- `GET /api/v1/tenants`
- `POST /api/v1/tenants`
- `PATCH /api/v1/tenants/{code}`
- `GET /api/v1/tenants/{code}/subtenants`
- `POST /api/v1/tenants/{code}/subtenants`
- `GET /api/v1/plans`
- `POST /api/v1/licenses/assign`
- `POST /api/v1/licenses/revoke`
- `GET /api/v1/tenants/{code}/entitlements`
- `GET /api/v1/tenants/{code}/quota`

## Events

Tenant/Licensing Service пишет события в `maniforge_tl_events`. CLI-диспетчер:

```bash
php maniforge/tenant-licensing/tools/dispatch_events.php
```

отправляет pending events в RBAC:

```http
POST /internal/v1/tenant-events
Authorization: Bearer <TENANT_LICENSING_INTERNAL_TOKEN>
```

RBAC отзывает sessions и refresh tokens для:

- `tenant.suspended`
- `tenant.disabled`
- `subtenant.suspended`
- `subtenant.disabled`
- `license.revoked`
- `license.expired`

Просроченные лицензии переводятся из `active` в `expired` отдельным job:

```bash
php maniforge/tenant-licensing/tools/expire_licenses.php
php maniforge/tenant-licensing/tools/dispatch_events.php
```

Первый скрипт создает `license.expired` events, второй доставляет их в RBAC.

## Configuration

- `TENANT_LICENSING_ENFORCEMENT`: `disabled`, `optional`, `strict`.
- `TENANT_LICENSING_INTERNAL_URL`: base URL нового сервиса для RBAC. Если пусто, RBAC использует локальный repository adapter.
- `TENANT_LICENSING_INTERNAL_TOKEN`: shared token для internal API и events.
- `TENANT_LICENSING_ADMIN_TOKEN`: bearer token для admin API нового сервиса.
- `TENANT_LICENSING_CACHE_TTL_SEC`: TTL кэша access-state в RBAC.
- `TENANT_LICENSING_TIMEOUT_SEC`: timeout internal HTTP вызовов.
- `RBAC_INTERNAL_URL`: base URL RBAC для dispatch events.

Пустые `TENANT_LICENSING_ADMIN_TOKEN` и `TENANT_LICENSING_INTERNAL_TOKEN` допускаются только в `APP_ENV=local|testing|test`. В остальных окружениях admin/internal endpoints закрываются с ошибкой конфигурации.

## Data Model

Миграция `maniforge/rbac/migrations/013_tenant_licensing_service.sql` создает отдельные таблицы:

- `maniforge_tl_tenants`
- `maniforge_tl_subtenants`
- `maniforge_tl_license_plans`
- `maniforge_tl_tenant_licenses`
- `maniforge_tl_quota_usage`
- `maniforge_tl_audit_log`
- `maniforge_tl_events`
- `maniforge_tenant_access_cache`

Лицензия по умолчанию живет на уровне tenant. Subtenant-level лимиты лучше добавлять позже через allocation/override, если появится коммерческая потребность.

## Source of truth: `maniforge_tl_*` vs RBAC tenant registry

- **Tenant Licensing (`maniforge_tl_*`)** — единственный source of truth для коммерческого lifecycle: tenant/subtenant status, plans, licenses, entitlements, quotas и lifecycle events.
- **RBAC registry (`maniforge_tenants`, `maniforge_subtenants`)** — legacy/technical registry для FK пользователей, сессий и ролей. Коды tenant/subtenant в RBAC должны совпадать с `maniforge_tl_tenants.code` и `maniforge_tl_subtenants.code`, но статус и лицензия берутся только из Tenant Licensing access-state.
- **Правило интеграции:** platform operator создаёт tenant в Tenant Licensing admin/API, затем RBAC users/roles создаются с тем же scope (`tenant_id` / `subtenant_id` = code). Автосинхронизация registry пока не реализована — при расхождении RBAC login будет отклонён по `tenant_not_active` / `license_not_active`, даже если user существует.
- **Demo/seed:** `demo_seed.php` и product landing используют одни и те же plan codes (`starter`, `business`, `enterprise`).

## HTTP smoke

```bash
php maniforge/tenant-licensing/tools/http_smoke.php
```

Env:

- `TL_SMOKE_BASE_URL` — например `http://127.0.0.1:8092/tenant-licensing`
- `TL_SMOKE_ADMIN_TOKEN` — если задан `TENANT_LICENSING_ADMIN_TOKEN`
- `TL_SMOKE_INTERNAL_TOKEN` — если задан `TENANT_LICENSING_INTERNAL_TOKEN`

Скрипт проверяет health, CRUD lifecycle через admin API, access-state, audit/events и revoke.
