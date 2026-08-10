# Maniforge Go — карта кода

Реестр всех Go-файлов основного контура. **При добавлении файла — обновлять эту таблицу.**

Обзор миграции: [MANIFORGE_GO_MIGRATION.md](MANIFORGE_GO_MIGRATION.md).

## Точки входа (`cmd/`)

| Файл | Назначение |
|------|------------|
| `cmd/rbac/main.go` | Запуск RBAC HTTP-сервиса (`:8093`, prefix `/rbac`) |
| `cmd/tenant-licensing/main.go` | Запуск Tenant Licensing (`:8094`) |
| `cmd/versioning/main.go` | Запуск Versioning HTTP (`:8096`, prefix `/versioning`) |
| `cmd/realtime/main.go` | Запуск Realtime WebSocket (`:8097`, `/ws`) |
| `cmd/migrate/main.go` | Применение SQL-миграций из `migrations/pg/` |
| `cmd/preflight/main.go` | Production preflight Go-контура (PostgreSQL + env guards) |
| `cmd/siem-forward/main.go` | Доставка `maniforge_siem_outbox` → SIEM webhook |
| `cmd/enterprise-journey/main.go` | Journey: lockout, TOTP MFA, require_mfa policy |
| `cmd/token-gen/main.go` | Генерация service tokens для ротации |
| `cmd/backup-drill/main.go` | Drill: счётчики критичных таблиц перед backup |
| `cmd/racebench/main.go` | Race/benchmark PG: блокировки, QPS (`make racebench`) |

## Инфраструктура (`internal/config`, `internal/db`)

| Файл | Назначение |
|------|------------|
| `internal/config/config.go` | Загрузка `.env`, структура `Config` (PG, RBAC, TL, PII, rate limit) |
| `internal/config/validate_production.go` | `ValidateProduction()` — обязательные проверки перед prod |
| `internal/db/postgres.go` | Подключение PostgreSQL (`pgx`), `Open` / `OpenOptional` / `Ping` |

## Платформа (`internal/platform/`)

| Файл | Назначение |
|------|------------|
| `internal/platform/httpx/response.go` | Единый JSON-ответ: `OK`, `Fail`, `JSON` |
| `internal/platform/auth/bearer.go` | Извлечение Bearer / query token из Fiber-контекста |
| `internal/platform/auth/guard.go` | Middleware проверки internal service token |
| `internal/platform/code/normalize.go` | Нормализация кодов tenant/subtenant/project |
| `internal/platform/audit/writer.go` | Запись в `maniforge_audit_log` (Go-модули) |
| `internal/platform/middleware/security.go` | Security headers (CSP, HSTS, X-Frame-Options, …) |
| `internal/platform/siem/notifier.go` | Webhook-доставка security events (HMAC) |

## RBAC — приложение

| Файл | Назначение |
|------|------------|
| `internal/rbac/app.go` | Сборка Fiber: auth, me, admin, projects, privacy, internal tenant-events |
| `internal/rbac/handler/auth.go` | HTTP: register, login, refresh, logout, reauth |
| `internal/rbac/handler/me.go` | HTTP: `/me`, profile, permissions, contexts, access |
| `internal/rbac/handler/admin.go` | HTTP: admin users/sessions/audit/roles/policies/ops-summary |
| `internal/rbac/handler/pd_admin.go` | HTTP: PD admin (operator-profile, purposes, subject-requests) |
| `internal/rbac/handler/internal.go` | HTTP: `POST /internal/v1/tenant-events` |
| `internal/rbac/handler/projects.go` | HTTP: projects, global-variables |
| `internal/rbac/handler/privacy.go` | HTTP: `GET /privacy/notice` |
| `internal/rbac/middleware/csrf.go` | CSRF для mutating запросов с сессией |
| `internal/rbac/middleware/ratelimit.go` | HTTP rate limiting (maniforge_rate_limits) |
| `internal/rbac/middleware/delegated.go` | Блокировка мутаций delegated read_only/operator |
| `internal/rbac/middleware/ratelimit_tl.go` | Rate limit Tenant Licensing admin API |
| `internal/rbac/handler/mfa.go` | HTTP: TOTP MFA `/me/mfa/*` |
| `internal/rbac/service/mfa.go` | Enroll/verify/disable TOTP + recovery codes |
| `internal/rbac/repository/mfa.go` | `maniforge_mfa_factors`, recovery codes |
| `internal/rbac/repository/siem_outbox.go` | Очередь SIEM `maniforge_siem_outbox` |
| `internal/rbac/repository/login_attempt.go` | Brute-force lockout (maniforge_login_attempts) |
| `internal/rbac/repository/rate_limit.go` | Скользящее окно rate limit |
| `internal/rbac/service/delegated_access.go` | Политика delegated grant для HTTP мутаций |
| `internal/rbac/service/rbac.go` | Роли и permissions в workspace |
| `internal/rbac/service/guard.go` | Step-up (action token), admin guards, policy IP/window |
| `internal/rbac/service/policy.go` | PolicyEngine: effective rules, IP/time/step-up |
| `internal/rbac/service/user_admin.go` | Batch status validation и dry-run summary |
| `internal/rbac/service/role_admin.go` | Guards assign/revoke ролей (иерархия) |
| `internal/rbac/service/tenant_lifecycle.go` | Internal tenant-events → revoke sessions |
| `internal/rbac/service/action_token.go` | Выдача `X-Action-Token` после reauth |
| `internal/rbac/service/context.go` | `/me/contexts`, `POST /auth/switch-context` (home/delegated) |
| `internal/rbac/repository/delegation.go` | `maniforge_tl_tenant_grants` — delegated contexts |
| `cmd/agency-demo-seed` | Seed agency-demo/client-demo для delegation journey (PG) |
| `migrations/pg/018_tenant_grants.sql` | Managed tenant grants (ось A) |
| `migrations/pg/019_warehouses.sql` | WMS: stock types, stocks, permissions, projects.warehouse_id |
| `cmd/warehouses` | HTTP-сервис Warehouses (:8098) |
| `cmd/warehouses-journey` | HTTP journey (register → stocks → projects) |
| `internal/warehouses/` | handler, service, repository (stocks, types, audit) |
| `internal/rbac/service/project.go` | Projects и scope variables |
| `internal/rbac/service/admin.go` | Admin API: users, policies, ops-summary, invites, batch-status |
| `internal/rbac/service/admin_extended.go` | Admin: sessions, audit export, roles CRUD, security-events |
| `internal/rbac/service/pd_admin.go` | PD admin service |
| `internal/rbac/service/role_scope.go` | Scoped custom role codes |
| `internal/rbac/repository/pd_admin.go` | PD operator profile, purposes, subject requests |
| `internal/rbac/repository/audit.go` | `maniforge_audit_log` |
| `internal/rbac/repository/security_event.go` | `maniforge_security_events` |
| `internal/rbac/repository/policy.go` | `maniforge_policy_rules` |
| `internal/rbac/repository/action_token.go` | `maniforge_action_tokens` |
| `internal/rbac/repository/scope_variable.go` | `maniforge_scope_variables` |
| `internal/versioning/query.go` | List changes / registry для HTTP API |
| `internal/versioninghttp/app.go` | Fiber-приложение Versioning |
| `internal/rbac/handler/health.go` | HTTP: `/health` + статус PostgreSQL |
| `internal/rbac/middleware/tenant.go` | Резолв tenant/subtenant; `SessionAuth` |
| `internal/rbac/service/auth.go` | Логин по телефону, licensing gate |
| `internal/rbac/service/session.go` | Выдача/проверка сессии, refresh, revoke |
| `internal/rbac/service/registration.go` | Self-reg, invite, bootstrap tenant/project |
| `internal/rbac/service/profile.go` | Обновление `maniforge_user_profile` (без logout) |
| `internal/rbac/service/user_security.go` | Критичные изменения users + revoke all sessions |
| `internal/rbac/repository/user.go` | `maniforge_users`: CRUD, identity, phone lookup |
| `internal/rbac/repository/user_profile.go` | `maniforge_user_profile`: мягкий профиль |
| `internal/rbac/repository/session.go` | `maniforge_sessions`, refresh tokens, revoke |
| `internal/rbac/repository/project.go` | `maniforge_projects`: default main, code by id |
| `internal/rbac/repository/role.go` | Роли и назначение при регистрации |
| `internal/rbac/repository/invite.go` | Registration invites |
| `internal/rbac/repository/pd.go` | Согласия ПДн при регистрации |
| `internal/rbac/security/password.go` | Argon2id / bcrypt verify & hash |
| `internal/rbac/security/pii.go` | Blind index и шифрование phone/email |

## Manifest Engine

| Файл | Назначение |
|------|------------|
| `cmd/manifest-engine/main.go` | Запуск Manifest Engine (`:8095`) |
| `cmd/manifest-journey/main.go` | HTTP journey: register → login → manifest CRUD |
| `cmd/manifest-refine-gen/main.go` | Генерация Refine scaffold в templates/refine-manifest/generated/ |
| `internal/manifestengine/refine/generate.go` | OpenAPI/manifest → Refine TS/React файлы |
| `templates/refine-manifest/index.php` | Браузерный CRUD UI (`/refine-manifest`) |
| `internal/manifestengine/presets/presets.go` | Supply chain presets: product, stock |
| `cmd/manifest-presets-seed/main.go` | CLI seed presets в project scope |
| `cmd/manifest-client-test-seed/main.go` | CLI seed: клиент → POST /manifests (origin=custom) + data API |
| `migrations/pg/013_manifest_presets.sql` | Seed product/stock для default tenant |
| `migrations/pg/016_control_plane_manifest_origin.sql` | field type catalog + manifest origin |
| `internal/dataplane/router.go` | Resolver data plane (shared → dedicated) |
| `internal/manifestengine/catalog/catalog.go` | Каталог типов полей (control plane) |
| `internal/manifestengine/app.go` | Fiber: `/api/v1/manifests`, `/api/data/{entity}` |
| `internal/manifestengine/model/*` | Типы, validate, filter, fieldpath, permissions, OpenAPI gen |
| `internal/manifestengine/repository/repository.go` | manifests, records, audit log |
| `internal/manifestengine/repository/filter.go` | JSONB filter + count для pagination meta |
| `internal/manifestengine/service/service.go` | CRUD + licensing gate + field RBAC + audit |
| `internal/manifestengine/handler/handler.go` | HTTP handlers |
| `internal/realtime/app.go` | Fiber + WebSocket hub |
| `internal/realtime/handler/ws.go` | WS upgrade (RBAC), ping/subscribe по subscription_id |
| `internal/realtime/handler/subscriptions.go` | CRUD /api/v1/subscriptions |
| `internal/realtime/handler/channels.go` | GET /api/v1/ws/channels (алиас suggest) |
| `internal/realtime/handler/internal.go` | POST /internal/v1/broadcast |
| `internal/realtime/hub/hub.go` | In-memory pub/sub, MatchesEvent по meta-каналам |
| `internal/realtime/channel/names.go` | entity.all/custom/platform, data.<code> |
| `internal/realtime/repository/subscription.go` | maniforge_realtime_subscriptions |
| `internal/realtime/service/subscription.go` | CRUD подписок + валидация каналов |
| `internal/realtime/service/scope.go` | Scope сессии для Realtime API |
| `internal/realtime/publisher/publisher.go` | HTTP client для Manifest Engine |
| `internal/realtime/service/broadcast.go` | Publish API |
| `internal/manifestengine/realtime/notify.go` | WS-события platform + custom |
| `migrations/pg/020_realtime_subscriptions.sql` | Таблица пользовательских WS-подписок |
| `docs/MANIFORGE_REALTIME.md` | Протокол WS для фронта (custom entities) |

Документация: [MANIFORGE_MANIFEST_ENGINE.md](MANIFORGE_MANIFEST_ENGINE.md), план: [MANIFORGE_MANIFEST_ENGINE_PLAN.md](MANIFORGE_MANIFEST_ENGINE_PLAN.md).

## Versioning

| Файл | Назначение |
|------|------------|
| `internal/versioning/recorder.go` | ChangeRecorder: insert/update/delete → `maniforge_ver_changes` |
| `internal/versioning/repository.go` | insert, isTableTracked, count |
| `migrations/pg/012_versioning.sql` | `maniforge_ver_registry`, `maniforge_ver_changes` |

## Tenant Licensing

| Файл | Назначение |
|------|------------|
| `internal/licensingclient/client.go` | RBAC → TL: `AssertAccess(tenant, project, workspace)` |
| `internal/tenantlicensing/app.go` | Fiber: internal access-state, admin read API |
| `internal/tenantlicensing/handler/handler.go` | HTTP-обработчики TL |
| `internal/tenantlicensing/repository/repository.go` | Чтение: access-state, entitlements, events |
| `internal/tenantlicensing/repository/write.go` | Запись: create tenant/subtenant, assign license |
| `internal/tenantlicensing/repository/repository_test.go` | Unit-тесты логики лицензии |

## Ключевые потоки

```text
login/register → licensingclient.AssertAccess → session.Issue
session auth   → security_version snapshot + licensing re-check
identity change → user_security → security_version++ → RevokeAllForUser
profile patch  → user_profile only (сессии живут)
```

## Миграции PostgreSQL

`migrations/pg/001` … `023` — см. файлы в каталоге; `021` — lockout/rate limits; `022` — TOTP MFA + SIEM; `023` — require_mfa_enrollment policy.

## Принципы проекта

Сводный реестр правил: [MANIFORGE_PRINCIPLES.md](MANIFORGE_PRINCIPLES.md).
