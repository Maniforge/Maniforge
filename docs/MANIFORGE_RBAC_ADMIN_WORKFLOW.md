# Бизнес-сценарий: RBAC admin (пользователи, роли, policies)

Tenant admin управляет пользователями, назначает роли, проверяет effective access и политики step-up. Сценарий проверен:

- `php maniforge/rbac/tools/rbac_admin_journey.php`
- `php maniforge/rbac/tools/http_smoke.php` — точечные admin API checks

## Предварительные условия

```bash
php -S 127.0.0.1:8092 -t public public/index.php
php maniforge/rbac/tools/rbac_admin_journey.php
```

При `RBAC_ADMIN_REQUIRE_STEP_UP=true` (по умолчанию) мутации требуют reauth + `X-Action-Token`.

---

## Этап 1. Создание пользователя

```http
POST /rbac/api/v1/auth/reauth
POST /rbac/api/v1/admin/users
X-Action-Token: {action_token}

{
  "login": "developer",
  "password": "DevPassword!12345",
  "phone": "+79001234567",
  "email": "dev@example.test",
  "status": "active",
  "reason": "team expansion"
}
```

---

## Этап 2. Назначение роли

```http
POST /rbac/api/v1/admin/user-roles/assign
X-Action-Token: {action_token}

{
  "user_id": 42,
  "role_code": "user",
  "reason": "default access"
}
```

---

## Этап 3. Effective access

```http
GET /rbac/api/v1/admin/effective-access?user_id=42
Authorization: Bearer {access_token}
```

Ответ: `access.roles`, `access.permissions` — итоговые права пользователя в текущем scope.

---

## Этап 4. Policies (step-up, IP, часы)

```http
GET /rbac/api/v1/admin/policies
POST /rbac/api/v1/admin/policies
X-Action-Token: {action_token}

{
  "reason": "security review",
  "allowed_ips": [],
  "allowed_hour_start_utc": 0,
  "allowed_hour_end_utc": 23,
  "require_step_up": true
}
```

---

## Этап 5. Ops summary

```http
GET /rbac/api/v1/admin/ops-summary
```

Сводка: users, sessions, audit, security events, step_up_required.

---

## Связанные API

| Действие | Endpoint |
|----------|----------|
| Список пользователей | `GET /api/v1/admin/users` |
| Роли пользователя | `GET /api/v1/admin/user-roles?user_id=` |
| Batch roles | `POST /api/v1/admin/user-roles/batch` |
| Audit | `GET /api/v1/admin/audit` |

---

## Быстрые команды

```bash
php maniforge/rbac/tools/rbac_admin_journey.php
php maniforge/rbac/tools/http_smoke.php
php maniforge/rbac/tools/business_journeys_suite.php
```
