# Maniforge Enterprise Hardening Checklist

Этот документ фиксирует, что нужно включить перед production rollout конструктора Maniforge RBAC + Tenant Licensing.

## Production profile

Обязательные настройки:

```dotenv
APP_ENV=production
TENANCY_MODE=multi
TENANT_LICENSING_ENFORCEMENT=strict
TENANT_LICENSING_ADMIN_TOKEN=<strong-random-token>
TENANT_LICENSING_INTERNAL_TOKEN=<strong-random-token>
TENANT_LICENSING_INTERNAL_URL=<internal-service-url>
RBAC_INTERNAL_TOKEN=<strong-random-token>
```

Проверки:

- `make preflight` — Go PostgreSQL + env guards (also in `verify-maniforge.sh`)
- `make platform-ops-journey` — Go smoke: TL suspend/reactivate + internal access-state (production box)
- `make manifest-journey` — Manifest Engine e2e
- `make backup-drill` — snapshot row counts before pg_dump
- PHP reference journeys (`maniforge/rbac/tools/*`) — **not shipped** in platform-core; optional lab repo

## Scheduler

Tenant Licensing lifecycle jobs (Go binaries + systemd timers):

- `bin/maniforge-tl-expire-licenses` — каждые 5–15 мин (`maniforge-tl-expire.timer`, default 10 min);
- `bin/maniforge-tl-dispatch-events` — каждые 1–5 мин (`maniforge-tl-dispatch.timer`, default 2 min);
- `make siem-forward` / `maniforge-siem-forward.timer` — если `RBAC_SIEM_WEBHOOK_ENABLED=true`;
- `deploy/scripts/backup-postgres.sh` + `maniforge-backup.timer` — ежедневный pg_dump;
- `php maniforge/rbac/tools/pd_retention_enforce.php` — **out-of-box** (PHP ref, Phase C+ backlog).

Установка timers: `sudo bash deploy/scripts/install-scheduler.sh`

## Personal data (152-ФЗ)

Дорожная карта и API: `docs/152FZ_COMPLIANCE.md`.

Production:

```dotenv
RBAC_PD_REGISTER_CONSENT_REQUIRED=true
RBAC_PD_REQUEST_SLA_DAYS=30
RBAC_PII_ENCRYPTION_ENABLED=true
RBAC_PII_ENCRYPTION_KEY=<base64-32-bytes>
```

## Security (реализовано в Go)

### Фаза A — runtime hardening

- Brute-force lockout (`maniforge_login_attempts`, 429 + `locked_until`)
- HTTP rate limiting RBAC (`maniforge_rate_limits`)
- Delegated mutation guard (`read_only` / `operator`) на всех Go-сервисах с сессией
- `RBAC_INTERNAL_TOKEN` для `POST /internal/v1/tenant-events`
- `config.ValidateProduction()` при старте сервисов

### Фаза B — enterprise controls

- HSTS (`Strict-Transport-Security` в production)
- Rate limit Tenant Licensing admin API (`TL_RATE_LIMIT_*`, event `tl.rate_limit.exceeded`)
- SIEM: `maniforge_siem_outbox` + webhook (`RBAC_SIEM_WEBHOOK_*`) + `make siem-forward`
- TOTP MFA: `POST /me/mfa/enroll|verify|disable`, recovery codes, reauth с `totp_code`

### Фаза C — policy & ops

- `require_mfa_enrollment` в `maniforge_policy_rules` — блок admin-мутаций без TOTP
- `make token-gen` — генерация токенов для ротации
- `make backup-drill` — снимок счётчиков критичных таблиц перед backup
- `make enterprise-journey` — автоматическая проверка lockout + MFA policy

### MFA API

| Метод | Путь | Permission |
|-------|------|------------|
| GET | `/api/v1/me/mfa` | `me.mfa.manage` |
| POST | `/api/v1/me/mfa/enroll` | `me.mfa.manage` |
| POST | `/api/v1/me/mfa/verify` | `me.mfa.manage` |
| POST | `/api/v1/me/mfa/disable` | `me.mfa.manage` |

`POST /api/v1/auth/reauth` принимает `password`, `totp_code` или `recovery_code`.

Для enroll TOTP на сервере обязателен `RBAC_PII_ENCRYPTION_KEY`.

### Ротация service tokens

```bash
make token-gen
# обновить .env на всех инстансах RBAC + TL + dispatch_events
# перезапустить сервисы
```

### Backup drill

```bash
make backup-drill
pg_dump -Fc -h $MANIFORGE_DB_HOST -U $MANIFORGE_DB_USER $MANIFORGE_DB_NAME > backup.dump
```

## Observability

Минимальный набор метрик:

- auth login success/fail, refresh fail, reauth fail;
- `tl.rate_limit.exceeded`, `auth.login.failed` (lockout);
- tenant access denied by reason;
- admin mutations by event type;
- `maniforge_siem_outbox` pending count;
- pending lifecycle events count and oldest age.

## Release gates

Production rollout блокируется, если:

- `TENANT_LICENSING_ENFORCEMENT` не `strict`;
- admin/internal tokens пустые в production;
- не пройдены `make preflight` и journey/smoke;
- нет active license у production tenant;
- pending lifecycle events не доставляются;
- нет runbook для emergency suspend/revoke tenant.

## Остаётся (backlog)

- envelope encryption для полей профиля (email/phone — готово);
- обязательный MFA на уровне plan/licensing (не только policy_rules);
- раздельные роли platform operator / support operator;
- immutable audit внешнему хранилищу (SIEM webhook — MVP);
- полный restore drill с проверкой RPO/RTO.
