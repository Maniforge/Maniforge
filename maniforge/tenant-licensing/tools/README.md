# Maniforge Tenant Licensing Tools

CLI-утилиты для lifecycle jobs и HTTP smoke Tenant Licensing.

## Скрипты

- `php maniforge/tenant-licensing/tools/expire_licenses.php` — переводит просроченные лицензии в `expired`;
- `php maniforge/tenant-licensing/tools/dispatch_events.php` — доставляет pending events в RBAC;
- `php maniforge/tenant-licensing/tools/http_smoke.php` — e2e проверка HTTP API (admin + internal access-state).

## HTTP smoke

```bash
export TL_SMOKE_BASE_URL=http://127.0.0.1:8092/tenant-licensing
# optional when tokens configured:
# export TL_SMOKE_ADMIN_TOKEN=...
# export TL_SMOKE_INTERNAL_TOKEN=...
php maniforge/tenant-licensing/tools/http_smoke.php
```

Проверяет health, tenant/subtenant/plan/license lifecycle, entitlements, quota, access-state, audit/events и revoke.

Связанные CLI-проверки RBAC: `php maniforge/rbac/tools/tenant_lifecycle_check.php`, `php maniforge/rbac/tools/check_all.php`.
