# Делегирование tenant (Maniforge)

Модель **не привязана к IT-агентствам**: один и тот же механизм подходит для MSP, интеграторов, партнёров, холдингов, операторов, реселлеров и внутренних команд платформы.

> **Глоссарий:** [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md) — если в бизнесе «субтенант» = **клиент под услугами оператора**, в Maniforge это **managed tenant**, а не поле `subtenant_id`.

## «Клиент-tenant» ≠ Subtenant

| Разговорный смысл | Сущность Maniforge |
|-------------------|---------------|
| Первый tenant продаёт услуги, ведёт проекты второго | **Principal** → **Managed** + **Grant** |
| «Субтенант» как вторая организация-клиент | ❌ Не `maniforge_tl_subtenants` у principal |
| `subtenant_id` в API (`main`, `west`) | **Workspace внутри** выбранного tenant (в т.ч. у managed) |

Пример: оператор `agency-demo` переключается на `client-demo` / `main` — это **managed tenant** + его workspace, не «subtenant-клиент» внутри agency.

## Термины

| Термин | Значение |
|--------|----------|
| **Tenant** | Организация — изолированная единица лицензирования и RBAC (`maniforge_tl_tenants`). |
| **Subtenant (workspace)** | Площадка / отдел **внутри одного** tenant. Не клиент и не второй tenant. |
| **Principal tenant** | Организация-оператор (агентство, холдинг, интегратор…), от имени которой выдаётся grant. |
| **Managed tenant** | Клиент / обслуживаемая организация — **отдельный** tenant с отдельной лицензией. |
| **Grant** | `maniforge_tl_tenant_grants`: principal → managed, уровень `operator` \| `admin` \| `read_only`. |
| **Project** | Контур работ внутри tenant (tenant-level или workspace-level). См. [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md). |

## Поток для сотрудника оператора (principal)

1. Оператор платформы (или автоматизация) создаёт tenants, subtenants, лицензии и **grant** (приватный Tenant Licensing API).
2. В home tenant principal создаётся учётная запись сотрудника (RBAC).
3. Сотрудник входит с `X-Tenant-ID` / `X-Subtenant-ID` home-области principal.
4. `GET /rbac/api/v1/me/contexts` возвращает:
   - **home** — все `(tenant, subtenant)`, где у login есть активная строка в `maniforge_users` или роль;
   - **delegated** — subtenants managed-tenants при активном grant, если login есть в principal tenant.
5. `POST /rbac/api/v1/auth/switch-context` меняет tenant/subtenant в сессии (с аудитом `auth.context_switch`).
   - Доступны только контексты из **home** или **delegated** с активным grant (`status = active`).
   - `grant_level` **read_only** — переключение разрешено; мутации API блокируются (`DelegatedMutationMiddleware`, код `delegated_read_only`).
   - Ответ switch-context и `GET /me` возвращают `delegated`, `grant_level`, `principal_tenant_id` для текущего scope.

Повторный вход с другим tenant/subtenant по-прежнему поддерживается (выбор контекста при login).

## Лимит managed tenants (`max_tenants`)

При `POST .../managed-tenants` для **principal** tenant проверяется активная лицензия и `limits_json.max_tenants` плана:

- считаются только grant со `status = active` у этого principal;
- новый grant или реактивация отозванного — 403, если `used >= max_tenants`;
- обновление уровня уже активного grant на тот же managed tenant лимит не расходует.

Демо: `agency-demo` на плане `operator` (`max_tenants: 25`), `client-demo` на `business`.

## Supported patterns

| Паттерн | Описание |
|---------|----------|
| **Single org** | Один tenant, один или несколько subtenant (проекты) без делегирования. |
| **Multi-project** | Несколько subtenant внутри одного tenant; переключение через login или contexts в рамках home. |
| **Operator + managed tenants** | Principal tenant + grants на managed tenants; home + delegated contexts и switch-context. |
| **Platform provisioning** | Создание tenants, лицензий и grants только через приватный Tenant Licensing API (токен платформы). |

## 152-ФЗ (технические меры MVP)

Полная карта: **`docs/152FZ_COMPLIANCE.md`** (согласия, запросы субъекта, профиль оператора, API).

Слой соответствия, **не специфичный для агентств**:

- **Изоляция tenant**: данные RBAC и licensing разделены по `tenant_id`; cross-tenant только через явный grant.
- **Минимальные привилегии**: `grant_level` ограничивает уровень делегирования (дальнейшее сопоставление с permissions — следующие итерации).
- **Аудит cross-tenant**: переключение контекста → `maniforge_audit_log` (`auth.context_switch` с previous/new scope); создание/отзыв grant → `maniforge_tl_audit_log` (`agency_grant.created` / `agency_grant.revoked` — коды событий в схеме).
- **Метаданные**: `metadata_json` у grant для ссылки на договор или внутреннюю пометку по обработке ПДн.
- **Субъект ПДн**: `GET /api/v1/privacy/notice`, `GET /api/v1/me/personal-data`, запросы `subject-requests`.

## API (кратко)

Параметр пути `{agencyCode}` — код **principal** tenant (имя в OpenAPI историческое; семантика — principal).

| Метод | Путь |
|-------|------|
| GET | `/tenant-licensing/api/v1/tenants/{principalCode}/managed-tenants` |
| POST | `/tenant-licensing/api/v1/tenants/{principalCode}/managed-tenants` |
| DELETE | `/tenant-licensing/api/v1/tenants/{principalCode}/managed-tenants/{managedCode}` |
| GET | `/rbac/api/v1/me/contexts` |
| POST | `/rbac/api/v1/auth/switch-context` |

## Демо

`php maniforge/rbac/tools/demo_seed.php` создаёт примеры `agency-demo`, `client-demo` (имена условные), grant и пользователей. UI: `/operator` (алиас `/agency`).

## Отложено

- Шаблоны развёртывания приложений в managed tenant.
- Шифрование ПДн at-rest (см. `152FZ_COMPLIANCE.md`), политики `operator` vs `admin` grant (тонкая матрица прав).
- Отдельные учётки principal в managed tenant vs чистое switch-context.
