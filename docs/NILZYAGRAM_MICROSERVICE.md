# Нильзяграм RBAC: Security-First проектирование

## Статус документа

Это проектное ТЗ и архитектурная спецификация перед стартом разработки.  
Код по этому документу не запускается до отдельной команды.

## Цель

Сделать отдельное приложение `Нильзяграм` как изолированную точку входа в стиле "микросервис внутри монолита", с максимально жесткой моделью безопасности:

- мультитенантность внутри мультитенантности;
- полноценный `Role-Based Access Control` + policy слой;
- контроль сессий и глобальный logout;
- шифрование чувствительных данных;
- отдельная админ-панель управления доступами и безопасностью.

## Точка входа и изоляция

Новая корневая точка входа:

- `maniforge/rbac/public/index.php`

Принцип изоляции:

- старые приложения не меняем;
- новый сервис живет в своем контуре (`maniforge/rbac/*`);
- отдельная маршрутизация, middleware цепочка, security policies, таблицы и логи.

## Референсные стандарты и решения

Документ опирается на проверенные практики:

- [OWASP ASVS 5.0](https://github.com/OWASP/ASVS/tree/master/5.0/en) (V6 Authentication, V7 Session, V8 Authorization, V11 Crypto, V14 Data Protection);
- [NIST SP 800-63B](https://pages.nist.gov/800-63-4/sp800-63b/session/) (session secret, timeout, re-authentication);
- [Keycloak Authorization Services](https://www.keycloak.org/docs/latest/authorization_services/index.html) как эталон enterprise RBAC/Policy Enforcement Point.

## Мультитенантность внутри мультитенантности

Два уровня изоляции:

1. `tenant` (организация/клиент);
2. `subtenant` (внутренний контур: филиал/команда/проект).

Каждая бизнес-сущность содержит:

- `tenant_id`;
- `subtenant_id`;
- `security_scope_id` (опционально для сверхтонкой сегментации).

Правило безопасности:

- запрос без валидного tenant-контекста отклоняется;
- доступ проверяется одновременно по `tenant`, `subtenant`, роли, policy и ownership.

## IAM и модель пользователей

### Разделение `user` и `user_profile`

`user` хранит критичные security-данные.  
`user_profile` хранит изменяемые пользовательские данные.

Критичные изменения в `user` (email для логина, пароль, MFA, статус аккаунта, блокировка, role-set) вызывают:

- инвалидирование всех активных сессий пользователя;
- отзыв refresh/session токенов;
- запись события в аудит.

Изменения в `user_profile` (display name, bio, avatar, timezone, locale) не роняют все сессии.

## RBAC + Policy модель доступа

### Базовые роли

- `super_admin` (только внутренний контур платформы);
- `tenant_admin`;
- `subtenant_admin`;
- `security_auditor`;
- `support_operator` (ограниченный);
- `moderator`;
- `user`.

### Принципы

- deny-by-default;
- least privilege;
- separation of duties;
- запрет прямой эскалации привилегий;
- проверка прав на уровне endpoint + use-case + data-row.

### Permission модель

Формат permission:

- `resource:action[:scope]`

Примеры:

- `post:create:own`
- `post:read:tenant`
- `session:revoke:any`
- `rbac:role.assign:subtenant`
- `admin:security.events:read`

### Policy слой (поверх RBAC)

RBAC определяет "может в принципе", policy определяет "может сейчас в контексте".  
Примеры policy:

- только рабочие часы;
- только доверенные сети;
- step-up MFA для чувствительных операций;
- обязательный повторный ввод пароля для критичных действий.

## Схема таблиц (минимальный security-набор)

### Identity / Access

- `tenants`
- `subtenants`
- `users`
- `user_profile`
- `roles`
- `permissions`
- `role_permissions`
- `user_roles`
- `policy_rules`
- `role_policy_bindings`

### Sessions / Tokens / Device

- `sessions`
- `session_events`
- `refresh_tokens`
- `trusted_devices`
- `mfa_factors`
- `password_history`

### Security / Audit / Ops

- `audit_log`
- `security_events`
- `outbox_events` (для асинхронных реакций)
- `rate_limit_buckets`

### Конфигурация и контекст

- `tenant_settings`
- `subtenant_settings`
- `feature_flags`

## Ключевые поля таблиц (обязательные)

`users`:

- `id`, `tenant_id`, `subtenant_id`
- `login`, `email`
- `password_hash` (`argon2id`)
- `mfa_required`, `mfa_enrolled_at`
- `security_version` (инкремент при критичных изменениях)
- `status` (`active`, `locked`, `disabled`)
- `last_password_changed_at`
- `created_at`, `updated_at`

`user_profile`:

- `user_id`
- `display_name`, `avatar_url`, `bio`
- `locale`, `timezone`
- `updated_at`

`sessions`:

- `id`, `user_id`, `tenant_id`, `subtenant_id`
- `session_secret_hash`
- `ip_hash`, `user_agent_hash`, `device_fingerprint_hash`
- `aal` (AAL1/AAL2/AAL3)
- `last_activity_at`, `expires_at`
- `revoked_at`, `revoke_reason`
- `security_version_snapshot`

`user_roles`:

- `id`, `user_id`, `role_id`, `tenant_id`, `subtenant_id`
- `assigned_by`, `assigned_at`, `expires_at`

## Шифрование и защита данных

### At-rest

- Пароли: только `argon2id` + per-user salt.
- Чувствительные поля PII: envelope encryption (`AES-256-GCM`) через KMS/HSM.
- Ключи не хранятся в БД, только key reference/id.

### In-transit

- TLS 1.2+ везде;
- HSTS;
- secure cookie + `HttpOnly` + `SameSite=Strict` (или `Lax` по обоснованию);
- запрет передачи session id в URL.

### Секреты и ключи

- ротация ключей по расписанию;
- versioned keys;
- отдельные ключи для подписи токенов, шифрования PII и service-to-service auth.

## Сессии и безопасность аккаунта

### Правила сессий

- session token только CSPRNG, минимум 128 бит энтропии;
- ротация session id после login/re-auth;
- idle timeout и absolute timeout;
- проверка fingerprint/риск-сигналов;
- возможность "logout all sessions".

### Re-authentication (NIST aligned)

- для критичных действий обязательна re-auth;
- step-up MFA для операций изменения security-контекста;
- по таймаутам ориентируемся на AAL-модель NIST (адаптируем под риск-профиль продукта).

### Критичные события, вызывающие глобальный logout

- смена пароля;
- смена email/логина;
- включение/смена MFA;
- изменение security-роли;
- подозрение на компрометацию;
- ручной revoke от админа/аудитора.

## Админ-панель безопасности

Путь:

- `maniforge/rbac/public/admin`

Разделы:

- Управление tenant/subtenant и их настройками;
- Управление пользователями (`user`) и профилями (`user_profile`);
- Роли, permissions, policy rules;
- Сессии: просмотр, выборочный revoke, глобальный logout;
- Security events и audit log (immutable);
- Настройки MFA, password policy, lockout policy, rate limit;
- Управление ключами (метаданные и статусы ротации без экспонирования ключевого материала).

Правила доступа в админ-панель:

- отдельный набор admin-ролей;
- обязательный MFA;
- IP allowlist для high-privilege ролей;
- запрет shared-аккаунтов;
- полный аудит всех admin-действий.

## API контракты (черновик v1)

### Public/Auth

- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `POST /api/v1/auth/logout`
- `POST /api/v1/auth/logout-all`
- `POST /api/v1/auth/reauth`

### Users/Profile

- `GET /api/v1/me`
- `PATCH /api/v1/me/profile`
- `PATCH /api/v1/me/security` (только с re-auth + MFA)

### RBAC

- `GET /api/v1/rbac/roles`
- `POST /api/v1/rbac/users/{id}/roles`
- `DELETE /api/v1/rbac/users/{id}/roles/{roleId}`
- `GET /api/v1/rbac/permissions/effective?user_id=...`

### Sessions/Security

- `GET /api/v1/security/sessions`
- `POST /api/v1/security/sessions/{id}/revoke`
- `GET /api/v1/security/events`
- `GET /api/v1/security/audit`

### Tenant Management (admin)

- `GET /api/v1/admin/tenants`
- `POST /api/v1/admin/tenants`
- `GET /api/v1/admin/subtenants`
- `POST /api/v1/admin/subtenants`

## Внутренние классы сервера (проектный состав)

### HTTP / Entry

- `Maniforge\Rbac\Http\Kernel`
- `Maniforge\Rbac\Http\Router`
- `Maniforge\Rbac\Http\Middleware\TenantResolverMiddleware`
- `Maniforge\Rbac\Http\Middleware\AuthMiddleware`
- `Maniforge\Rbac\Http\Middleware\RbacMiddleware`
- `Maniforge\Rbac\Http\Middleware\CsrfMiddleware`
- `Maniforge\Rbac\Http\Middleware\RateLimitMiddleware`
- `Maniforge\Rbac\Http\Middleware\SecurityHeadersMiddleware`

### Security Core

- `Maniforge\Rbac\Security\AuthenticationService`
- `Maniforge\Rbac\Security\SessionService`
- `Maniforge\Rbac\Security\ReauthService`
- `Maniforge\Rbac\Security\RbacService`
- `Maniforge\Rbac\Security\PolicyEngine`
- `Maniforge\Rbac\Security\EncryptionService`
- `Maniforge\Rbac\Security\PasswordService`
- `Maniforge\Rbac\Security\MfaService`

### Domain Services

- `Maniforge\Rbac\Domain\User\UserService`
- `Maniforge\Rbac\Domain\User\UserProfileService`
- `Maniforge\Rbac\Domain\Admin\AdminSecurityService`
- `Maniforge\Rbac\Domain\Tenant\TenantService`

### Persistence

- `Maniforge\Rbac\Repository\UserRepository`
- `Maniforge\Rbac\Repository\UserProfileRepository`
- `Maniforge\Rbac\Repository\SessionRepository`
- `Maniforge\Rbac\Repository\RoleRepository`
- `Maniforge\Rbac\Repository\PermissionRepository`
- `Maniforge\Rbac\Repository\AuditLogRepository`

## Security требования (MVP уровня enterprise)

- OWASP ASVS уровень не ниже L2 для всех auth/session/authorization функций;
- строгая валидация входных данных и централизованная обработка ошибок;
- полный audit trail для security и admin операций;
- защита от brute-force, credential stuffing, session fixation, CSRF, IDOR;
- SAST + dependency scanning + secret scanning в CI;
- минимальные права для сервисных аккаунтов и БД-пользователей.

## План запуска разработки (не выполнять до отдельной команды)

1. Создать каркас `maniforge/rbac/*` и entrypoint `maniforge/rbac/public/index.php`.
2. Поднять schema migration для identity/rbac/session/audit таблиц.
3. Реализовать auth + session + logout-all + re-auth.
4. Реализовать RBAC/permissions/policy engine.
5. Реализовать admin panel с MFA и аудитом.
6. Провести security review по чеклисту OWASP ASVS.

## Решение по текущему запросу

Документация переписана под:

- новую точку входа `maniforge/rbac/public/index.php`;
- полноценный role/access/user/security контур;
- шифрование и максимальную безопасность;
- разделение `user` и `user_profile` с глобальным logout по критичным изменениям;
- таблицы roles/users/sessions/variables и сопутствующий security контур;
- отдельную админ-панель.

Далее ожидается отдельная команда на старт реализации.

## Реализация: текущий статус (v0)

После согласования стартовали базовую реализацию каркаса `maniforge/rbac`:

- entrypoint: `maniforge/rbac/public/index.php`
- rewrite: `maniforge/rbac/public/.htaccess`
- ядро HTTP: `App\Maniforge\Rbac\Http\Kernel`, `App\Maniforge\Rbac\Http\Router`
- middleware: security headers + rate limit + tenancy resolve
- auth/session: login, me, logout (server-side sessions table)
- admin endpoint: список пользователей с role check
- SQL schema: `docs/MANIFORGE_RBAC_SCHEMA.sql`

Доступные endpoint'ы на текущем шаге:

- `GET /rbac/health`
- `POST /rbac/api/v1/auth/login`
- `POST /rbac/api/v1/auth/logout`
- `POST /rbac/api/v1/auth/logout-all`
- `POST /rbac/api/v1/auth/refresh`
- `POST /rbac/api/v1/auth/reauth`
- `GET /rbac/api/v1/me`
- `GET /rbac/api/v1/me/permissions`
- `GET /rbac/api/v1/me/access`
- `POST /rbac/api/v1/me/security/password`
- `GET /rbac/api/v1/admin/users`
- `GET /rbac/api/v1/admin/sessions`
- `POST /rbac/api/v1/admin/sessions/revoke`
- `POST /rbac/api/v1/admin/sessions/batch-revoke`
- `GET /rbac/api/v1/admin/audit`
- `GET /rbac/api/v1/admin/security-events`
- `GET /rbac/api/v1/admin/roles`
- `GET /rbac/api/v1/admin/permissions`
- `GET /rbac/api/v1/admin/user-roles?user_id=...`
- `POST /rbac/api/v1/admin/user-roles/assign`
- `POST /rbac/api/v1/admin/user-roles/revoke`
- `POST /rbac/api/v1/admin/user-roles/batch`
- `GET /rbac/api/v1/admin/effective-access?user_id=...`

UI (MVP):

- `GET /rbac/` (основная страница сервиса)
- `GET /rbac/admin` (админ-панель)
- `GET /rbac/api-docs` (страница описания API)
- `GET /rbac/api-docs/openapi.yaml` (raw OpenAPI)

Migration и деплой:

- SQL миграции: `maniforge/rbac/migrations/*.sql`
- CLI раннер: `php maniforge/rbac/tools/migrate.php`
- создание первого администратора: `php maniforge/rbac/tools/create_admin.php <login> <password> <email> [tenant] [subtenant]`

Безопасность (добавлено в реализации):

- CSRF-check для state-changing запросов (`POST/PUT/PATCH/DELETE`), кроме `auth/login` и `auth/refresh`.
- Brute-force lockout для логина по `(tenant, subtenant, login, ip_hash)`.
- Параметры lockout: `RBAC_LOGIN_MAX_FAILS`, `RBAC_LOGIN_LOCK_MINUTES`.
- Смена security-пароля требует `step-up re-auth` и завершает все сессии пользователя.
- История паролей в `maniforge_password_history` (анти-reuse последних паролей).
- Admin endpoint'ы защищены двойной проверкой: role gate + permission gate.
- Защита role-операций: блок `self-escalation`, блок `self-demotion` для privileged ролей, запрет снятия последнего `tenant_admin/subtenant_admin`.
- Для role mutation (`assign/revoke/batch`) обязателен `reason`, пишется в аудит.
- Поддержан batch API с лимитом `RBAC_BATCH_MAX_ITEMS` и атомарной транзакцией.
- В batch API поддержан режим `dry_run` для безопасной предварительной проверки.
- Для session revoke (`single/batch`) обязателен `reason`; batch поддерживает `dry_run`.
- Полный журнал изменений: `docs/CHANGELOG_MANIFORGE_RBAC.md`.

Контракты API:

- OpenAPI спецификация (минимальная): `docs/MANIFORGE_RBAC_OPENAPI.yaml`.

Ограничения текущей реализации:

 - policy-engine покрывает DB-configured rules (IP + время + require step-up), но без продвинутого ABAC;
- ABAC контур пока частичный (RBAC + простые policy rules);
- нет cryptographic envelope для PII полей (заложено в целевую архитектуру).

## Коммерческие профили поставки: Single vs Multi

Цель: продавать один и тот же продукт клиентам разного уровня зрелости без переписывания ядра.

### Профили

- `multi` (enterprise): полный режим `tenant + subtenant`.
- `single` (default SaaS/on-prem): один фиксированный контекст (`default/default`).
- `disabled` (исключение): tenancy-проверки выключены, только для спец-кейсов.

### Конфигурационные флаги

- `TENANCY_MODE=multi|single|disabled`
- `DEFAULT_TENANT_ID=default`
- `DEFAULT_SUBTENANT_ID=default`
- `TENANCY_HEADERS_REQUIRED=true|false`

### Поведение API по режимам

- `multi`: обязательны `X-Tenant-ID` и `X-Subtenant-ID`, иначе `400`.
- `single`: заголовки опциональны; при отсутствии используются значения по умолчанию.
- `disabled`: контекст tenancy не участвует в авторизации, но аудит и RBAC продолжают работать.

### Архитектурное правило совместимости

- Таблицы всегда содержат `tenant_id` и `subtenant_id` даже в `single`.
- Весь код получает контекст через единый `TenantResolver`.
- В `single`/`disabled` меняется только стратегия резолва контекста, а не доменная логика.

### Миграция Multi -> Single без даунтайма

1. Включить режим совместимости: заголовки становятся опциональными.
2. Назначить `DEFAULT_TENANT_ID`/`DEFAULT_SUBTENANT_ID`.
3. Перевести клиентов на single-профиль конфигурации.
4. Переключить `TENANCY_MODE=single`.
5. После стабилизации убрать зависимость внешних интеграций от tenant-заголовков.

### Миграция Single -> Multi без даунтайма

1. Подготовить tenants/subtenants и mapping пользователей.
2. Включить прием tenant-заголовков в soft-режиме.
3. Перевести API-клиентов на передачу контекста.
4. Переключить `TENANCY_MODE=multi`.
5. Включить обязательность заголовков (`TENANCY_HEADERS_REQUIRED=true`).

### Ограничения режима `disabled`

- Разрешать только контрактно и временно.
- Обязателен аудит решения и owner с датой пересмотра.
- Запрещено для regulated-контуров и high-risk данных.
