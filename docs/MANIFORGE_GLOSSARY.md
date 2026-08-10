# Maniforge — глоссарий: бизнес-термины и технические сущности

Документ снимает путаницу между **разговорной** терминологией («субтенант = клиент») и **кодом Maniforge** (`subtenant` = workspace внутри одного tenant).

**Архитектура платформы (оси A/B, слои, ADR):** [MANIFORGE_ARCHITECTURE.md](MANIFORGE_ARCHITECTURE.md).

Связанные документы:

- [MANIFORGE_TENANT_DELEGATION.md](MANIFORGE_TENANT_DELEGATION.md) — оператор и клиент как **два tenant**
- [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md) — проекты и данные внутри **одного** tenant
- [MANIFORGE_TEAM_PROJECT_WORKFLOW.md](MANIFORGE_TEAM_PROJECT_WORKFLOW.md) — проекты и переменные scope

---

## Главное правило

В Maniforge **два разных смысла** — не смешивать:

| Ось | Вопрос | Ответ в Maniforge |
|-----|--------|-------------|
| **A. Делегирование** | «Второй tenant пользуется услугами первого, первый ведёт его как админ?» | **Principal tenant** + **Managed tenant** + **Grant** |
| **B. Внутренняя структура** | «Как делим одну организацию на отделы/площадки?» | **Subtenant (workspace)** + **Project** внутри **одного** tenant |

**Subtenant в коде — всегда ось B, никогда не «клиент-tenant».**

---

## Таблица соответствий

| Как говорят в бизнесе | Что имеют в виду | Техническая сущность Maniforge | Таблица / API |
|----------------------|------------------|-------------------------|--------------|
| Организация, компания, tenant | Юрлицо на платформе | **Tenant** | `maniforge_tl_tenants.code` → в RBAC `tenant_id` |
| Клиент оператора, «второй tenant» | Отдельная организация под обслуживанием | **Managed tenant** | Отдельный `maniforge_tl_tenants.code`, grant |
| Оператор, интегратор, MSP, продавец услуг | Кто обслуживает клиентов | **Principal tenant** | `maniforge_tl_tenant_grants.principal_tenant_code` |
| Договор / право вести клиента | Доступ оператора к клиенту | **Grant** | `maniforge_tl_tenant_grants` (`operator` \| `admin` \| `read_only`) |
| Переключиться на клиента | Работа в данных клиента | **Switch context** (delegated) | `POST /rbac/api/v1/auth/switch-context` |
| Субтенант = клиент | ❌ Неверно для Maniforge | Используйте **managed tenant** | — |
| Субтенант, площадка, филиал | Workspace **внутри** своей организации | **Subtenant (workspace)** | `maniforge_tl_subtenants` (`tenant_code` + `code`) |
| Проект расчёта, продукт, контур | Изолированный контур работ | **Project** | `maniforge_projects` |
| Проект на уровне всей организации | Без привязки к workspace | **Tenant-project** | `maniforge_projects.subtenant_id = ''`, `scope=tenant` |
| Проект внутри площадки | Subtenant + project | **Subtenant-project** | `maniforge_projects.subtenant_id = main` (и др.) |
| Склад, WMS-узел | Данные модуля Warehouses | **Stock** | `maniforge_wh_stocks` (+ `project_id`, `scope_visibility`) |
| Доступ оператора к складу клиента без switch | Grant + галочки на сущности | **Delegation share** | `shared_grant_tenant_ids_json`, админка / PATCH stocks |

---

## Сценарий: оператор ведёт клиента (ось A)

```text
Principal tenant     agency-demo          ← ваш «первый tenant», продаёт услуги
        │
        │  maniforge_tl_tenant_grants
        ▼
Managed tenant       client-demo          ← ваш «второй tenant», клиент
        │
        └── subtenant main                 ← не «клиент», а workspace внутри client-demo
                └── project main           ← контур работ (расчёт, склад, …)
                        └── stocks, …
```

**Поток для сотрудника оператора:**

1. Login в `agency-demo` / `main` (home).
2. `GET /rbac/api/v1/me/contexts` → в `delegated` виден `client-demo`.
3. `POST /auth/switch-context` → `{ "tenant_id": "client-demo", "subtenant_id": "main" }`.
4. `switch-project` при необходимости → проекты **клиента**.
5. Warehouses / Projects — только в scope **managed tenant**, с аудитом `auth.context_switch`.

Демо: `php maniforge/rbac/tools/demo_seed.php` → `agency-demo`, `client-demo`.

---

## Сценарий: одна организация без оператора (ось B)

```text
Tenant               acme-corp
 ├── subtenant       main              ← основная площадка
 │    └── project     main              ← default контур
 ├── subtenant       logistics         ← другой отдел / бренд
 │    └── project     wh-2026
 └── project (tenant-level)  hq        ← subtenant_id = '', scope = tenant
```

Здесь **нет** второго tenant: только деление **внутри** `acme-corp`.

---

## Что означает `subtenant_id` в API и сессии

| Контекст | Значение |
|----------|----------|
| `tenant_id` + `subtenant_id` в login | Выбран **workspace внутри** этого tenant |
| `switch-context` на `client-demo` / `main` | Вы в **managed tenant**, workspace `main` |
| `scope_level: subtenant` (переменные) | Переменная уровня workspace, не «клиент» |
| `scope_visibility: subtenant` (склады) | Склад общий для всех проектов **этого workspace** |

По паре `(tenant_code, subtenant_code)` в licensing всегда можно получить родительский **tenant** — это FK в `maniforge_tl_subtenants`, а не «subtenant как отдельный клиент».

---

## Чего не делать в интеграциях

| Ошибка | Правильно |
|--------|-----------|
| Создавать «клиента» как subtenant у principal | Создать **managed tenant** + **grant** |
| Хранить `client_id` в поле `subtenant_id` principal | Два разных `tenant_id`; переключение через contexts |
| Ждать, что `subtenant` = второй tenant в лицензии | Второй tenant — отдельная строка в `maniforge_tl_tenants` |
| Путать **tenant-project** с managed tenant | Tenant-project — проект организации; managed — другой `tenant_id` |

---

## Роли (кратко)

| Роль | Типичный scope |
|------|----------------|
| `tenant_admin` | Весь tenant, tenant-projects, grants (если principal) |
| `subtenant_admin` | Один workspace (subtenant) |
| `user` | Проекты с membership |

При **delegated** сессии права проверяются в scope **managed tenant**; `delegated_read_only` блокирует мутации.

---

## Имена в коде (для разработчиков)

| Константа / поле | Смысл |
|------------------|--------|
| `EntityScope::PROJECT_SCOPE_TENANT` | `maniforge_projects.subtenant_id === ''` |
| `EntityScope::PROJECT_SCOPE_SUBTENANT` | проект привязан к workspace |
| `scope_visibility: tenant` на stock | Общий склад tenant с галочками subtenant |
| `principal_tenant_id` в `/me` | Кто выдал делегирование |
| `membership: delegated` в organizations | Контекст через grant |

---

## История термина «subtenant»

В Maniforge **subtenant** закреплён как **внутренний workspace** (см. [MANIFORGE_TENANT_DELEGATION.md](MANIFORGE_TENANT_DELEGATION.md), термины).

Если в переговорах с заказчиком говорят «субтенант = клиент», в ТЗ и UI лучше писать:

- **Клиент (managed tenant)** — `client-demo`
- **Площадка (workspace)** — `main`, `west`
- **Не** использовать слово «субтенант» для клиента в пользовательской документации.

В API и БД имена полей (`subtenant_id`) **не меняются** — это стабильный контракт платформы.
