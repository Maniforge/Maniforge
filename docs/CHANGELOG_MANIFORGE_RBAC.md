# CHANGELOG Maniforge RBAC

Формат: ручной лог изменений по итерациям разработки `maniforge/rbac`.

## 2026-06-02

### Итерация 1: базовый каркас

- Добавлен изолированный entrypoint `maniforge/rbac/public/index.php` и rewrite.
- Реализованы `Kernel`, `Router`, tenant resolver (`multi|single|disabled`).
- Добавлены auth endpoints: `login`, `logout`, `me`.
- Реализован базовый RBAC gate для admin users endpoint.
- Добавлены таблицы и SQL схема (users, roles, sessions, audit и т.д.).
- Добавлен bootstrap скрипт создания администратора.

### Итерация 2: session hardening

- Добавлен `refresh_token` контур и ротация refresh на `auth/refresh`.
- Добавлен `logout-all` с массовым revoke сессий.
- Добавлен `reauth` (step-up) и хранение `mfa_verified_at`.
- Добавлен policy engine для admin операций (IP allowlist + time window).
- Добавлены admin endpoints для сессий (`list`, `revoke`).
- Добавлен MVP admin UI: `maniforge/rbac/public/admin/index.php`.

### Итерация 3: audit/security observability

- Добавлены `security_events` (immutable event stream).
- Добавлены admin endpoints чтения `audit` и `security-events`.
- Включено логирование admin-действий в аудит.
- Добавлены пошаговые SQL миграции `001..003`.
- Добавлен CLI раннер миграций `maniforge/rbac/tools/migrate.php`.

### Итерация 4: auth hardening

- Добавлен CSRF middleware для state-changing запросов.
- Исключены из CSRF-check только `auth/login` и `auth/refresh`.
- Добавлена защита от брутфорса логина (`maniforge_login_attempts`, lockout policy).
- Обновлен admin UI: передача `X-CSRF-Token` после login.
- Добавлена миграция `004_login_attempts.sql`.

### Итерация 5: security password flow

- Добавлен endpoint `POST /api/v1/me/security/password`.
- Для смены пароля обязателен свежий `step-up re-auth`.
- При смене пароля происходит:
  - запись в `maniforge_password_history`,
  - инкремент `security_version`,
  - обновление `last_password_changed_at`,
  - глобальный `logout-all` всех сессий и refresh-токенов пользователя.
- Добавлена миграция `005_password_history.sql`.
- Добавлены env-параметры `RBAC_PASSWORD_MIN_LENGTH` и `RBAC_PASSWORD_HISTORY_CHECK`.

### Итерация 6: permission gate + OpenAPI

- В admin-контуре добавлена проверка `permission` поверх role gate.
- Реализована выборка effective permissions через `role_permissions`.
- Добавлены permission seeds и назначение permissions системным ролям.
- Добавлена миграция `006_permissions_seed.sql`.
- Добавлен OpenAPI файл: `docs/MANIFORGE_RBAC_OPENAPI.yaml`.

### Итерация 7: effective permissions + user role management

- Добавлен endpoint `GET /api/v1/me/permissions`.
- Добавлены admin endpoints:
  - `GET /api/v1/admin/roles`
  - `GET /api/v1/admin/permissions`
  - `GET /api/v1/admin/user-roles?user_id=...`
  - `POST /api/v1/admin/user-roles/assign`
  - `POST /api/v1/admin/user-roles/revoke`
- Добавлено логирование assign/revoke role в `audit` и `security_events`.
- Расширен MVP admin UI блоком roles/permissions/user-role management.
- Добавлена миграция `007_additional_permissions_seed.sql` для уже развернутых инсталляций.

### Итерация 8: dangerous role ops guard + access snapshot

- Добавлены endpoint'ы:
  - `GET /api/v1/me/access`
  - `GET /api/v1/admin/effective-access?user_id=...`
- Добавлены guard-правила для role mutation:
  - запрет self-escalation в privileged роли;
  - запрет self-demotion из privileged ролей;
  - запрет снятия последнего `tenant_admin/subtenant_admin` в контуре.
- Расширены permission seeds (`admin.user_access.read`).
- Обновлены OpenAPI и admin UI для access snapshot.

### Итерация 9: batch API + reason enforcement

- Для `assign/revoke` роли сделан обязательный `reason`.
- Добавлен endpoint `POST /api/v1/admin/user-roles/batch`:
  - принимает массив role-мутаторов;
  - применяет их атомарно в одной транзакции;
  - поддерживает действия `assign`/`revoke`;
  - валидирует лимит `RBAC_BATCH_MAX_ITEMS`.
- Добавлен новый permission `admin.user_roles.bulk`.
- Расширен admin UI блоком batch role mutations.

### Итерация 10: UI pages for review

- Добавлены UI-страницы:
  - `GET /` (главная),
  - `GET /admin` (админка),
  - `GET /api-docs` (API описание),
  - `GET /api-docs/openapi.yaml` (raw OpenAPI).
- Добавлен `PageController` и HTML response helper.
- Обновлен CSP: для UI путей разрешены inline style/script, для API сохраняется строгий CSP.

### Итерация 11: batch dry-run

- Для `POST /api/v1/admin/user-roles/batch` добавлен `dry_run` режим.
- В `dry_run` выполняются все проверки и возвращается прогноз summary без записи в БД.
- Dry-run действия пишутся в аудит отдельным событием.
- Обновлены OpenAPI, UI и документация под `dry_run`.

### Итерация 12: local access fix

- Для локального запуска дефолтный `TENANCY_MODE` переключен на `single` (если `.env` отсутствует).
- Исправлен dev-старт сервера через `public` docroot и router script.
- Подтверждена доступность страниц `/`, `/admin`, `/api-docs` со статусом `200`.

### Итерация 13: sessions batch API

- Для `POST /api/v1/admin/sessions/revoke` добавлен обязательный `reason`.
- Добавлен endpoint `POST /api/v1/admin/sessions/batch-revoke`:
  - `reason` обязателен;
  - поддержан `dry_run`;
  - транзакционное применение batch revoke.
- Добавлен permission `admin.sessions.bulk` и seed-миграция `008_sessions_bulk_permission_seed.sql`.
- Расширены OpenAPI, админ UI и документация под session batch.

### Итерация 14: checkpoint summary

- Добавлен контрольный summary-файл: `docs/MANIFORGE_RBAC_CHECKPOINT_SUMMARY.md`.
- Зафиксировано текущее состояние, ключевые API, действующие защиты и остаточный roadmap до production-level.

### Итерация 15: batch user status changes

- Добавлен endpoint `POST /api/v1/admin/users/batch-status`:
  - поддержаны целевые статусы `active|locked|disabled`;
  - обязателен `reason`;
  - поддержан `dry_run` с прогнозом summary без записи в БД;
  - фактическое применение выполняется атомарно в одной транзакции.
- Добавлен permission `admin.users.status.bulk` и seed-миграция `009_users_batch_status_permission_seed.sql`.
- Добавлено аудит/наблюдаемость для dry-run и apply сценариев batch status.
- Обновлены OpenAPI и admin UI (новый блок batch user status changes).

### Итерация 16: DB-backed policy rules

- Добавлены endpoints:
  - `GET /api/v1/admin/policies`
  - `POST /api/v1/admin/policies`
- Policy rules вынесены в БД (`maniforge_policy_rules`) на scope `tenant/subtenant`:
  - `allowed_ips`,
  - `allowed_hour_start_utc`,
  - `allowed_hour_end_utc`,
  - `require_step_up`.
- `PolicyEngine` переведен на effective rules из БД с fallback к `.env`.
- Добавлены permissions:
  - `admin.policies.read`,
  - `admin.policies.update`.
- Добавлена миграция `010_policy_rules_and_permissions.sql` и расширен admin UI блоком policy rules management.

### Итерация 17: user/role service extraction

- Часть бизнес-логики вынесена из `AdminController` в отдельные сервисы:
  - `RoleAdminService` (guard role mutation + batch dry-run summary),
  - `UserAdminService` (валидатор allowed statuses + batch status dry-run summary).
- `AdminController` стал тоньше: контроллер оставляет orchestration/response, а domain-правила выполняются в сервисах.
- Внешний API и контракты endpoint'ов не изменены.

### Итерация 18: operational preflight checks

- Добавлен CLI preflight-скрипт: `maniforge/rbac/tools/preflight.php`.
- Preflight проверяет:
  - подключение к БД;
  - наличие и применение всех SQL миграций;
  - наличие ключевых таблиц RBAC/security;
  - наличие критичных permissions для admin-batch/policy операций;
  - наличие пользователей в контуре (warn, если пусто).
- Скрипт выдает machine-friendly exit code:
  - `0` — preflight ok,
  - `1` — есть критичные несоответствия,
  - `2` — инфраструктурный блокер (например, DB connect).

### Итерация 19: unified checks pipeline

- Добавлен единый раннер `maniforge/rbac/tools/check_all.php`:
  - запускает `preflight.php`,
  - затем `integration_check.php`,
  - завершает пайплайн при первом non-zero коде.
- `integration_check.php` теперь по умолчанию запускает preflight перед интеграционными проверками.
- Для отладки доступен флаг `--skip-preflight` у `integration_check.php`.

### Итерация 20: tools runbook and CLI UX

- Добавлен runbook `maniforge/rbac/tools/README.md`:
  - сценарии local/CI запуска,
  - матрица exit-кодов,
  - типовые troubleshooting шаги.
- Для `preflight.php`, `integration_check.php`, `check_all.php` добавлен `--help`.
- CLI-поведение проверок сделано более само-документируемым для on-call/ops сценариев.

### Итерация 21: CI pipeline template

- Добавлен GitHub Actions workflow: `.github/workflows/rbac-checks.yml`.
- Workflow поднимает `mysql:8`, применяет миграции и запускает `php maniforge/rbac/tools/check_all.php`.
- Включены триггеры для `push`, `pull_request` и `workflow_dispatch`.
- Проверки ограничены релевантными путями RBAC/docs/CI-конфига для более быстрых прогонов.

### Итерация 22: CI smoke/full split

- Workflow `.github/workflows/rbac-checks.yml` разделен на 2 job:
  - `rbac-smoke` (быстрый preflight),
  - `rbac-full` (полный `check_all`), запускается после smoke.
- Обновлен runbook `maniforge/rbac/tools/README.md` под двухступенчатый CI pipeline.

### Итерация 23: HTTP smoke checks

- Добавлен CLI скрипт `maniforge/rbac/tools/http_smoke.php` для e2e проверки живого API-контура.
- Smoke сценарий покрывает:
  - `auth/login`,
  - `auth/reauth`,
  - `admin/users`,
  - `admin/users/batch-status` (`dry_run`),
  - `admin/policies` (`GET` + idempotent `POST`).
- Обновлен runbook `maniforge/rbac/tools/README.md` с инструкциями по env и exit-кодам для HTTP smoke.

### Итерация 24: local URL + optional CI e2e smoke

- Локальные дефолты URL переведены на `http://127.0.0.1:8092`:
  - `.env.example`,
  - `config/app.php`,
  - `templates/home.php`.
- Workflow `.github/workflows/rbac-checks.yml` расширен optional job `rbac-http-smoke`:
  - запускается вручную через `workflow_dispatch` и `run_http_smoke=true`,
  - поднимает локальный php-server и выполняет `http_smoke.php`.
- `http_smoke.php` обновлен для поддержки cookie-сессии между запросами (корректная CSRF-проверка в e2e).

### Итерация 25: integration coverage for role batch guards

- Расширен `maniforge/rbac/tools/integration_check.php`:
  - проверяет наличие `admin.user_roles.bulk`;
  - покрывает `RoleAdminService::simulateBatchSummary`;
  - покрывает транзакционный `RoleRepository::applyRoleMutationsBatch`;
  - проверяет идемпотентный повтор batch role mutations.
- Добавлены интеграционные сценарии guard-правил:
  - запрет privileged self-escalation;
  - запрет privileged self-demotion;
  - разрешение снятия не последнего scope-admin;
  - запрет снятия последнего `tenant_admin/subtenant_admin` в контуре.

### Итерация 26: typed OpenAPI contract

- `docs/MANIFORGE_RBAC_OPENAPI.yaml` обновлен до версии `0.2.0`.
- Добавлены переиспользуемые OpenAPI components:
  - типовые error responses (`401/403/404/422`);
  - схемы session/login/refresh/access snapshot;
  - схемы roles/permissions/user-role assignments;
  - схемы policy rules и batch summaries.
- Ключевые auth/admin endpoints дополнены `application/json` response schemas.
- Batch endpoints (`user-roles`, `sessions`, `users status`) описаны через явные request/summary schemas.
- OpenAPI проверен YAML-парсером и проверкой внутренних `$ref`.

### Итерация 27: API docs visual refresh

- Обновлена UI-страница `GET /api-docs`.
- Добавлены:
  - hero-блок с быстрыми CTA;
  - summary-карточки по OpenAPI/batch/security;
  - flow-блоки для auth, step-up и безопасных mutations;
  - визуальный список ключевых endpoint'ов с method badges;
  - security contract notes;
  - пример batch role mutation payload;
  - список ключевых typed schemas.

### Итерация 28: marketplace-style API reference

- Страница `GET /api-docs` переработана из landing-формата в API reference:
  - добавлена фиксированная боковая навигация по разделам и методам;
  - добавлен поиск по методам/ключевым словам;
  - добавлены подробные карточки endpoints с method/access badges;
  - добавлены таблицы параметров и security contract;
  - добавлены request/response JSON-примеры;
  - добавлен раздел типовых ошибок;
  - добавлен справочник ключевых OpenAPI schemas.
- Для JSON-примеров добавлены copy-кнопки без внешних зависимостей.

### Итерация 29: Tenant/Licensing service boundary

- Добавлен отдельный Tenant/Licensing service:
  - новый HTTP entrypoint `maniforge/tenant-licensing/public/index.php`;
  - admin API для tenants/subtenants/plans/licenses/entitlements/quota;
  - internal API `access-state` для runtime-проверок RBAC.
- Добавлена миграция `013_tenant_licensing_service.sql`:
  - registry-таблицы `maniforge_tl_tenants` и `maniforge_tl_subtenants`;
  - `maniforge_tl_license_plans`, `maniforge_tl_tenant_licenses`, `maniforge_tl_quota_usage`;
  - audit/events tables и RBAC cache `maniforge_tenant_access_cache`.
- RBAC интегрирован через `TenantLicensingClient`:
  - проверки tenant/subtenant/license state на login, refresh и session authentication;
  - cache/fallback policy через `TENANT_LICENSING_ENFORCEMENT`.
- Добавлен lifecycle event flow:
  - Tenant/Licensing пишет pending events;
  - `dispatch_events.php` отправляет их в RBAC;
  - RBAC отзывает sessions/refresh tokens при tenant/subtenant suspension и license revoke/expiry.
- Обновлены `.env.example`, `preflight.php` и документация `docs/MANIFORGE_TENANT_LICENSING_SERVICE.md`.
- Исправления после аудита:
  - `TenantLicensingClient` безопасно обрабатывает сбои кэша, HTTP status и `ok=false`;
  - пустые admin/internal tokens разрешены только в local/test окружениях;
  - `expires_at` валидируется до записи лицензии;
  - добавлен job `expire_licenses.php` для генерации `license.expired` events.
