# Maniforge RBAC Checkpoint Summary

Дата: 2026-06-02

## Что уже готово

- Изолированное приложение `maniforge/rbac` с отдельной точкой входа.
- UI страницы:
  - `/` (главная),
  - `/admin` (админка),
  - `/api-docs` (документация API),
  - `/api-docs/openapi.yaml` (raw OpenAPI).
- Auth/security контур:
  - login, refresh, logout, logout-all, reauth(step-up),
  - смена пароля с глобальным завершением сессий,
  - CSRF для state-changing endpoint,
  - lockout от brute-force.
- Session security:
  - single revoke с `reason`,
  - batch revoke с `reason` + `dry_run` + транзакция.
- RBAC/permissions:
  - role gate + permission gate,
  - effective permissions/access endpoint,
  - assign/revoke role с обязательным `reason`,
  - batch role mutation с `reason` + `dry_run` + транзакция,
  - batch смена статусов пользователей (`active|locked|disabled`) с `reason` + `dry_run` + транзакция,
  - policy rules management через БД (IP allowlist, time window, require step-up).
- Observability:
  - `audit_log` + `security_events`,
  - отдельные события для admin/security операций.
- DevOps/operations:
  - migration runner `maniforge/rbac/tools/migrate.php`,
  - preflight checks `maniforge/rbac/tools/preflight.php`,
  - unified checks runner `maniforge/rbac/tools/check_all.php`,
  - optional e2e API smoke `maniforge/rbac/tools/http_smoke.php`,
  - integration coverage для policy rules, user status batch и role batch guard-сценариев,
  - типизированный OpenAPI контракт с common schemas/responses для auth/admin/batch endpoints,
  - tools runbook `maniforge/rbac/tools/README.md`,
  - CI workflow template `.github/workflows/rbac-checks.yml` (smoke + full + optional e2e jobs),
  - набор миграций `001..010`,
  - bootstrap скрипт admin пользователя.

## Текущие ключевые API

- Auth: `/api/v1/auth/*`
- Me: `/api/v1/me`, `/api/v1/me/permissions`, `/api/v1/me/access`, `/api/v1/me/security/password`
- Admin: users/sessions/audit/security-events/policies/roles/permissions/user-roles/effective-access/user-status-batch
- Batch:
  - `/api/v1/admin/user-roles/batch`
  - `/api/v1/admin/sessions/batch-revoke`
  - `/api/v1/admin/users/batch-status`

## Защиты, которые уже действуют

- deny-by-default через role+permission checks;
- step-up для чувствительных операций;
- запреты опасных role-операций:
  - self-escalation,
  - self-demotion для privileged ролей,
  - снятие последнего scope-admin.

## Что осталось для production-level

- Envelope encryption для чувствительных PII полей (KMS/HSM-ready).
- Дальнейшее расширение OpenAPI примерами payload'ов и более детальными audit/security event schemas.
- Дальнейшее расширение интеграционных тестов для endpoint-level batch/session сценариев.
