# Maniforge — scope сущностей (tenant + project)

> **Термины:** [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md) — «клиент» = **managed tenant + grant**, не `subtenant_id`.

## Базовое правило

**Любая доменная сущность** (склады, продукты, …) живёт в разрезе:

```text
Tenant (principal или managed — одинаковые правила)
 └── Project (default code = main)
      └── Сущность
```

`subtenant_id` — **технический workspace** внутри tenant (маршрут сессии, tenant-project vs subtenant-project).  
Первичная бизнес-ось данных: **`tenant_id` + `project_id`**, не «клиент как subtenant».

## Две независимые оси

```text
Ось A — Делегирование (два tenant)
  Principal ──grant──► Managed
  Сущность владеет owner tenant; доступ peer — в админке (см. ниже)

Ось B — Структура внутри одного tenant
  Tenant → Workspace (subtenant) → Project → Stocks, …
```

Сессия: `tenant_id` + `subtenant_id` + `project_id` (контур работ, обычно `main`).

## Видимость внутри одного tenant

| `scope_visibility` | Кто видит |
|--------------------|-----------|
| `project` (default) | Текущий `project_id` сессии |
| `subtenant` | Все проекты workspace (`project_id IS NULL`) |
| `tenant` | Tenant-wide; `shared_subtenant_ids` — галочки workspace |

Создание: `POST /warehouses/api/v1/stocks` — по умолчанию project + сессия; `tenant_level` / `target_subtenant_id` — для админов.

## Доступ principal ↔ managed (родитель ↔ дочка)

Сущность **принадлежит** tenant-владельцу (`maniforge_wh_stocks.tenant_id`).  
Дополнительно в **`shared_grant_tenant_ids_json`** — коды peer-tenant, которым разрешено **чтение** при активном grant.

Настройка (**tenant_admin**, админка / API):

| Поле API | Смысл |
|----------|--------|
| `delegation_share_tenant_ids` | Явный список peer (`agency-demo`, `client-demo`) |
| `share_with_principal: true` | Для managed — открыть principal по grant |
| `share_with_managed: true` | Для principal — все active managed по grant |
| `GET /warehouses/api/v1/delegation/grant-peers` | Список peer для галочек в UI |

**Чтение peer:** тот же **code проекта** в сессии (`main` ↔ `main`).  
**Запись** в чужом tenant — только после `switch-context` (код `delegated_entity_read_only`).

Пример: склад в `client-demo` / project `main` + `share_with_principal` → виден в `agency-demo` / project `main` без switch.

## Проект ↔ склад

`maniforge_projects.warehouse_id` — корневой `type=warehouse` в scope проекта.

## Оператор с клиентом

1. Данные клиента — в **managed tenant** (после switch или через delegation_share из home principal).
2. Grant — Tenant Licensing; лимит `max_tenants` на плане principal.

## Модули с entity scope

| Модуль | Таблица | Документация |
|--------|---------|--------------|
| Warehouses | `maniforge_wh_stocks` | [MANIFORGE_WAREHOUSES.md](MANIFORGE_WAREHOUSES.md) |
| Products | `maniforge_products` | [MANIFORGE_PRODUCTS.md](MANIFORGE_PRODUCTS.md) |
| Inventory (движения) | `maniforge_inv_movements` | [MANIFORGE_INVENTORY.md](MANIFORGE_INVENTORY.md) |

## Код и миграции

`EntityScope`, `EntityScopeResolver`, `EntityDelegationShareService`, `DefaultProjectService`.

- `034_entity_scope_project.sql`
- `035_tenant_level_default_projects.sql`
- `036_entity_delegation_share.sql`
- `037_products_core.sql`, `038_products_permissions.sql`
- `039_inventory_core.sql`, `040_inventory_permissions.sql`
