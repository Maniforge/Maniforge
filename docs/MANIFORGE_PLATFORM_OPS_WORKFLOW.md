# Бизнес-сценарий: platform ops (lifecycle tenant)

Platform/ops команда приостанавливает tenant или subtenant; RBAC блокирует вход. Сценарий проверен:

- `php maniforge/rbac/tools/tenant_lifecycle_check.php` — CLI repository
- `php maniforge/rbac/tools/platform_ops_journey.php` — HTTP (TL + RBAC)

## Предварительные условия

```bash
php -S 127.0.0.1:8092 -t public public/index.php
php maniforge/rbac/tools/platform_ops_journey.php
```

---

## Этап 1. Suspend subtenant

```http
PATCH /tenant-licensing/api/v1/tenants/{tenant}/subtenants/main
Content-Type: application/json

{ "name": "Main", "status": "suspended" }
```

RBAC login → **403** (`deny_reason: subtenant_not_active`).

---

## Этап 2. Suspend tenant

```http
PATCH /tenant-licensing/api/v1/tenants/{tenant}
{ "name": "...", "status": "suspended" }
```

Login также блокируется с `tenant_not_active`.

---

## Этап 3. Access-state и events

```http
GET /tenant-licensing/internal/v1/tenants/{tenant}/subtenants/main/access-state
GET /tenant-licensing/api/v1/events?tenant_code={tenant}
```

Lifecycle events записываются в TL (`subtenant.suspended`, `tenant.suspended` и т.д.).

---

## Этап 4. Dispatch в RBAC (опционально)

Cron или ручной запуск:

```bash
php maniforge/tenant-licensing/tools/dispatch_events.php
```

Либо прямой вызов internal endpoint RBAC:

```http
POST /rbac/internal/v1/tenant-events

{
  "event_type": "tenant.suspended",
  "tenant_code": "t-abc123",
  "subtenant_code": "main",
  "payload": { "source": "ops" }
}
```

RBAC отзывает сессии в затронутом scope и пишет security event `tenant_lifecycle.event.processed`.

---

## Восстановление доступа

```http
PATCH .../subtenants/main  { "status": "active" }
PATCH .../tenants/{tenant} { "status": "active" }
```

После reactivate login снова возвращает **200**.

---

## Быстрые команды

```bash
php maniforge/rbac/tools/tenant_lifecycle_check.php
php maniforge/rbac/tools/platform_ops_journey.php
php maniforge/tenant-licensing/tools/dispatch_events.php
php maniforge/rbac/tools/business_journeys_suite.php
```
