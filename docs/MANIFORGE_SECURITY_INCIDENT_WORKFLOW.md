# Бизнес-сценарий: реагирование на инцидент безопасности

Tenant admin блокирует скомпрометированного пользователя, отзывает сессии и проверяет журналы. Сценарий проверен:

- `php maniforge/rbac/tools/security_incident_journey.php`

## Предварительные условия

```bash
php -S 127.0.0.1:8092 -t public public/index.php
php maniforge/rbac/tools/security_incident_journey.php
```

---

## Этап 1. Создание пользователя администратором

```http
POST /rbac/api/v1/auth/reauth
POST /rbac/api/v1/admin/users
X-Action-Token: {action_token}

{
  "login": "suspect",
  "password": "...",
  "phone": "+79001234567",
  "reason": "onboarding"
}
```

---

## Этап 2. Блокировка через batch-status

```http
POST /rbac/api/v1/admin/users/batch-status
X-Action-Token: {action_token}

{
  "reason": "security_incident",
  "items": [{ "user_id": 42, "status": "locked" }]
}
```

При статусах `locked` / `disabled` все активные сессии пользователя отзываются автоматически.

---

## Этап 3. Проверка блокировки

- Повторный login пользователя → **403** (`Аккаунт не активен`)
- Старый bearer token → **401**
- Security event `auth.login.blocked`

---

## Этап 4. Аудит и security-events

| Что смотреть | API |
|--------------|-----|
| Security events | `GET /rbac/api/v1/admin/security-events` |
| Audit log | `GET /rbac/api/v1/admin/audit` |
| Активные сессии | `GET /rbac/api/v1/admin/sessions` |

Ожидаемые события: `admin.users.batch_status.updated`, `auth.login.blocked`, audit `admin.users.batch_status`.

---

## Дополнительно: batch-revoke sessions

```http
POST /rbac/api/v1/admin/sessions/batch-revoke
X-Action-Token: {action_token}

{
  "reason": "incident_response",
  "session_ids": ["..."],
  "dry_run": false
}
```

---

## Быстрые команды

```bash
php maniforge/rbac/tools/security_incident_journey.php
php maniforge/rbac/tools/business_journeys_suite.php
```
