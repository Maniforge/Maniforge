# Maniforge RBAC Tools

CLI-утилиты для операционной проверки и обслуживания `maniforge/rbac`.

## Быстрый старт

- Локальный HTTP-сервер (product landing + все модули):
  - `php -S 127.0.0.1:8092 -t public public/index.php`
  - `/` — product landing (Maniforge), `/pricing` — тарифы, `/api` — публичные и приватные API
  - `/admin` — единый вход; модули tenant/platform по ролям (`/admin/tenant`, `/admin/platform`)
- Изолированный RBAC docroot (только smoke/CI):
  - `php -S 127.0.0.1:8092 -t maniforge/rbac/public maniforge/rbac/public/index.php`
  - `/` — модульный landing со ссылкой на product page (не админка)
- Preflight (инфраструктура, миграции, таблицы, permissions):
  - `php maniforge/rbac/tools/preflight.php`
- 152-ФЗ (таблицы PD, permissions, operator profile / purposes):
  - `php maniforge/rbac/tools/pd_compliance_check.php`
  - `php maniforge/rbac/tools/pd_bootstrap_compliance.php [tenant] [operator_name]`
  - `php maniforge/rbac/tools/pd_migrate_pii_encryption.php` (после включения `RBAC_PII_ENCRYPTION_*`)
  - `php maniforge/rbac/tools/pd_retention_enforce.php` (cron: SLA, consent retention, purge audit)
  - `php maniforge/rbac/tools/pd_compliance_journey_check.php` (E2E: notice → register → subject request → resolve)
  - Документация: `docs/152FZ_COMPLIANCE.md`
- Интеграционные проверки (с автоматическим preflight):
  - `php maniforge/rbac/tools/integration_check.php`
- Tenant lifecycle проверки (лицензия, suspend/revoke/expire, seats):
  - `php maniforge/rbac/tools/tenant_lifecycle_check.php`
- Делегирование tenant (grant, contexts, switch-context, max_tenants):
  - `php maniforge/rbac/tools/delegation_check.php` (требует `demo_seed.php` для agency-demo/client-demo)
- Entity meta и phone-first credentials:
  - `php maniforge/rbac/tools/entity_meta_check.php`
  - `php maniforge/rbac/tools/credential_architecture_check.php`
  - `php maniforge/rbac/tools/phone_login_scope_check.php`
- Единый раннер всех проверок:
  - `php maniforge/rbac/tools/check_all.php`
- Demo seed для enterprise SaaS pilot:
  - `php maniforge/rbac/tools/demo_seed.php`
- Назначить роль существующему пользователю (например platform `super_admin`):
  - `php maniforge/rbac/tools/grant_role.php admin super_admin default default`
- Создать пользователя с ролью:
  - `php maniforge/rbac/tools/create_admin.php <login> <password> <email> [tenant] [subtenant] [role]`
- HTTP smoke (живой API-контур):
  - `php maniforge/rbac/tools/http_smoke.php`
  - `php maniforge/tenant-licensing/tools/http_smoke.php`
- Сценарий нового пользователя (регистрация → проект → invite → versioning):
  - `php maniforge/rbac/tools/new_user_journey_check.php` — CLI без HTTP-сервера
  - `php maniforge/rbac/tools/new_user_http_journey.php` — HTTP E2E (нужен сервер на `:8092`)
  - `php maniforge/rbac/tools/race_condition_check.php` — rate limit, license, invite race
  - `php maniforge/rbac/tools/new_user_suite.php` — все проверки подряд
  - Документация: `docs/MANIFORGE_NEW_USER_WORKFLOW.md`
- Бизнес-сценарии (delegation, security, team, platform ops, RBAC admin):
  - `php maniforge/rbac/tools/agency_delegation_http_journey.php` — HTTP: agency-admin → switch-context → client-demo
  - `php maniforge/rbac/tools/security_incident_journey.php` — HTTP: lock user, revoke sessions, audit
  - `php maniforge/rbac/tools/team_project_journey.php` — HTTP: project, membership, switch-project, variables
  - `php maniforge/rbac/tools/platform_ops_journey.php` — HTTP: suspend tenant/subtenant, lifecycle events
  - `php maniforge/rbac/tools/rbac_admin_journey.php` — HTTP: create user, assign role, effective-access, policies
  - `php maniforge/rbac/tools/business_journeys_suite.php` — demo_seed + delegation_check + все HTTP journeys
  - Документация: `docs/MANIFORGE_AGENCY_DELEGATION_WORKFLOW.md`, `MANIFORGE_SECURITY_INCIDENT_WORKFLOW.md`, `MANIFORGE_TEAM_PROJECT_WORKFLOW.md`, `MANIFORGE_PLATFORM_OPS_WORKFLOW.md`, `MANIFORGE_RBAC_ADMIN_WORKFLOW.md`

## Справка по флагам

- `php maniforge/rbac/tools/preflight.php --help`
- `php maniforge/rbac/tools/integration_check.php --help`
- `php maniforge/rbac/tools/tenant_lifecycle_check.php`
- `php maniforge/rbac/tools/check_all.php --help`
- `php maniforge/rbac/tools/http_smoke.php --help`
- `php maniforge/tenant-licensing/tools/http_smoke.php --help`

Поддерживаемые опции:

- `integration_check.php --skip-preflight` — пропустить preflight (локальная отладка).

## Exit codes

### `preflight.php`

- `0` — preflight успешно пройден.
- `1` — обнаружены критичные несоответствия (например, отсутствуют таблицы/permissions/примененные миграции).
- `2` — инфраструктурный блокер (например, ошибка подключения к БД).

### `integration_check.php`

- `0` — проверки пройдены.
- `1` — assertion failure в интеграционных проверках.
- `2` — инфраструктурный блокер (ошибка подключения к БД или провал preflight).

### `check_all.php`

- Возвращает код первой упавшей стадии:
  - сначала `preflight.php`,
  - затем `integration_check.php`,
  - затем `tenant_lifecycle_check.php`,
  - затем `delegation_check.php`.

### `tenant_lifecycle_check.php`

- `0` — lifecycle проверки пройдены.
- `1` — assertion failure или unhandled exception.
- `2` — инфраструктурный блокер может быть возвращён PHP/DB-слоем до assertions.

### `http_smoke.php`

- `0` — HTTP smoke пройден.
- `1` — одна или несколько smoke-проверок не пройдены.
- `2` — отсутствуют обязательные env для запуска smoke.

## Рекомендации для CI

- Рекомендуемый двухступенчатый pipeline:
  - smoke stage: `php maniforge/rbac/tools/preflight.php`
  - full stage: `php maniforge/rbac/tools/check_all.php`
- Опционально для e2e API:
  - отдельный job с запуском php-server и `php maniforge/rbac/tools/http_smoke.php`
- В GitHub Actions есть готовый пример: `.github/workflows/rbac-checks.yml`
  - manual input `run_http_smoke=true` включает дополнительный e2e job.
- Если нужно разделить вручную без `check_all`:
  - `php maniforge/rbac/tools/preflight.php`
  - `php maniforge/rbac/tools/integration_check.php --skip-preflight`
  - `php maniforge/rbac/tools/tenant_lifecycle_check.php`

## Demo SaaS flow

`demo_seed.php` создаёт повторяемый тестовый контур:

- tenant/subtenant в Tenant Licensing;
- plan и active license с `seats_max`;
- tenant admin и обычного пользователя в RBAC;
- роли `tenant_admin` и `user` внутри demo scope.

Переменные окружения:

- `MANIFORGE_DEMO_TENANT` — по умолчанию `demo`;
- `MANIFORGE_DEMO_SUBTENANT` — по умолчанию `main`;
- `MANIFORGE_DEMO_PLAN` — по умолчанию `starter`;
- `MANIFORGE_DEMO_ADMIN_LOGIN` / `MANIFORGE_DEMO_ADMIN_PASSWORD`;
- `MANIFORGE_DEMO_USER_LOGIN` / `MANIFORGE_DEMO_USER_PASSWORD`;
- `MANIFORGE_DEMO_SEATS_MAX` — по умолчанию `25`.

После запуска используйте `X-Tenant-ID` и `X-Subtenant-ID` из вывода для RBAC HTTP smoke и tenant admin console.

## Tenant lifecycle check

`tenant_lifecycle_check.php` создаёт временный tenant/subtenant/plan/license и проверяет:

- active tenant с active license допускается RBAC access client;
- `seats_max` запрещает активацию сверх лимита;
- suspended subtenant возвращает `subtenant_not_active`;
- suspended tenant возвращает `tenant_not_active`;
- revoked и expired license возвращают `license_not_active`;
- lifecycle events и audit записи создаются.

Скрипт принудительно использует `TENANT_LICENSING_ENFORCEMENT=strict` и локальный repository access, затем удаляет временные данные.

## Delegation check

`delegation_check.php` проверяет:

- создание/наличие grant principal → managed;
- `contextsForSession` с `grant_level` в delegated;
- `switchContext` меняет `tenant_id` сессии на managed tenant;
- enforcement `max_tenants` (403 на второй grant при лимите 1).

Перед запуском: `php maniforge/rbac/tools/demo_seed.php`.

## Business journeys suite

`business_journeys_suite.php` последовательно запускает:

1. `demo_seed.php` (можно пропустить: `--skip-demo-seed`)
2. `delegation_check.php` (CLI)
3. HTTP journeys (можно пропустить: `--skip-http`):
   - `agency_delegation_http_journey.php`
   - `security_incident_journey.php`
   - `team_project_journey.php`
   - `platform_ops_journey.php`
   - `rbac_admin_journey.php`

HTTP journeys используют общий helper `journey_http_common.php` и env `JOURNEY_BASE_URL` (по умолчанию `http://127.0.0.1:8092/rbac`).

## Типичные проблемы

- `Access denied for user 'root'@'localhost'`:
  - проверьте `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` в `.env`;
  - убедитесь, что пользователь БД имеет права на схему.
- Missing migrations:
  - выполните `php maniforge/rbac/tools/migrate.php`.
- No users found:
  - создайте admin:  
    `php maniforge/rbac/tools/create_admin.php <login> <password> <email> [tenant] [subtenant]`
- HTTP smoke missing env:
  - задайте `RBAC_SMOKE_BASE_URL`, `RBAC_SMOKE_LOGIN`, `RBAC_SMOKE_PASSWORD`.
- Сайт не открывается по `zaxar-app:8092`:
  - используйте `http://127.0.0.1:8092` для локального запуска.
