# Maniforge Credential Architecture

Три уровня ключей для RBAC и платформы.

## 1. Platform credentials (сервис → сервис)

| Переменная | Назначение |
|------------|------------|
| `TENANT_LICENSING_ADMIN_TOKEN` | Tenant Licensing admin API (tenants, plans, licenses) |
| `TENANT_LICENSING_INTERNAL_TOKEN` | Internal access-state, RBAC lifecycle hooks |
| `RBAC_INTERNAL_TOKEN` | `POST /internal/v1/tenant-events` |

Не привязаны к user/session. **Не передавать** в браузерные приложения.

## 2. Session credentials (пользователь → RBAC API)

### Login (единственный запрос с tenant на границе)

`POST /api/v1/auth/login`

- `TENANCY_MODE=single` — tenant из env (`DEFAULT_TENANT_ID`, `DEFAULT_SUBTENANT_ID`).
- `TENANCY_MODE=multi` — `X-Tenant-ID` + `X-Subtenant-ID` **или** `tenant_id` / `subtenant_id` в JSON.

Ответ:

```json
{
  "ok": true,
  "user": { "id": 1, "login": "..." },
  "credentials": {
    "session": {
      "credential_type": "session_access",
      "access_token": "...",
      "refresh_token": "...",
      "session_id": "...",
      "expires_in": 43200,
      "scope": { "tenant_id": "demo", "subtenant_id": "main" }
    }
  },
  "session": { "...": "same as credentials.session (compat)" },
  "csrf_token": "..."
}
```

### Все остальные RBAC endpoints

Только:

```http
Authorization: Bearer <access_token>
X-CSRF-Token: <csrf_token>   # POST/PATCH/DELETE
```

`X-Tenant-ID` **не нужен** — scope зашит в `maniforge_sessions`.

Исключение: `POST /api/v1/auth/switch-context` — смена `tenant_id`/`subtenant_id` в теле (с проверкой home/delegated).

## 3. Action credential (чувствительные admin-операции)

После `POST /api/v1/auth/reauth` (пароль + Bearer session):

```json
{
  "ok": true,
  "step_up": true,
  "credentials": {
    "action": {
      "credential_type": "action",
      "action_token": "...",
      "expires_in": 900,
      "purpose": "admin_sensitive"
    }
  }
}
```

Для admin write при `require_step_up`:

```http
Authorization: Bearer <access_token>
X-Action-Token: <action_token>
X-CSRF-Token: <csrf_token>
```

Допустимо и legacy: свежий `mfa_verified_at` в сессии (`RBAC_MFA_STEPUP_MAX_AGE_SEC`).

TTL: `RBAC_ACTION_TOKEN_TTL_SEC` (default 900). При reauth старые action tokens сессии отзываются.

## Жизненный цикл

- Logout / revoke session / password change → session + refresh + action tokens отзываются.
- Refresh → старая сессия отзывается, выдаётся новая пара access/refresh с тем же scope.

## См. также

- `docs/MANIFORGE_ENTERPRISE_HARDENING.md`
- `docs/MANIFORGE_RBAC_OPENAPI.yaml`
