# Maniforge Warehouses — складская иерархия (платформенный модуль)

Модуль **не копирует** enterprise.local как монолит. Это узел Maniforge: tenant scope, RBAC, **аудит**, **пользователи-акторы**, versioning.

> Термины «клиент / субтенант»: [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md). Данные клиента оператора — в **managed tenant** после `switch-context`, не в subtenant principal.

## Принцип

| Слой | Где живёт |
|------|-----------|
| Кто сделал | `maniforge_users` → `created_by_user` / `updated_by_user` / `actor_user` в API |
| Что сделал | `maniforge_audit_log` (`warehouses.stock.*`) с `diff` на update |
| Снимок данных | `maniforge_ver_changes` (`maniforge_wh_stocks`) |
| Доступ | permissions `warehouses.*` + лицензия tenant |

Enterprise WMS (`stocks`, `inventory`, движения) — **отдельная система**. Интеграция через `entity_meta` и API, а не перенос всего домена.

## Данные

- `maniforge_wh_stock_types` — каталог типов и правил вложенности
- `maniforge_wh_stocks` — дерево в scope **tenant → subtenant → project** (`scope_visibility`, см. [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md))
- `maniforge_projects.warehouse_id` — опциональный корневой склад проекта

Сессия по умолчанию: проект `main`. Создание склада без параметров — в текущий project scope.

### Scope при создании склада

| visibility | Кто задаёт | Видимость |
|------------|------------|-----------|
| `project` (default) | все с `warehouses.write` | только `project_id` сессии |
| `subtenant` | subtenant_admin+ | все проекты subtenant |
| `tenant` | tenant_admin; `shared_subtenant_ids` — галочки subtenant | выбранные или все subtenant |

`target_subtenant_id` — создание склада для другого subtenant (tenant_admin).

### Доступ principal ↔ managed (родитель ↔ клиент)

Склад принадлежит **tenant-владельцу** + **project**. В админке (tenant_admin) на узле:

- `delegation_share_tenant_ids` — peer по активному grant;
- `share_with_principal` / `share_with_managed` — быстрые флаги;
- `GET /warehouses/api/v1/delegation/grant-peers` — список peer для UI.

Peer видит склад при совпадении **code проекта** сессии. Изменение — только в контексте владельца (`switch-context`). См. [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md).

### Проект ↔ склад

`warehouse_id` на проекте — корневой `type=warehouse`, видимый в scope проекта.

## API (`/warehouses`)

| Method | Path | Permission |
|--------|------|------------|
| GET | `/health` | — |
| GET | `/api/v1/stock-types` | `warehouses.types.read` |
| GET | `/api/v1/stocks` | `warehouses.read` |
| GET | `/api/v1/stocks/tree` | `warehouses.read` |
| POST | `/api/v1/stocks` | `warehouses.write` |
| GET | `/api/v1/stocks/{id}` | `warehouses.read` |
| PATCH | `/api/v1/stocks/{id}` | `warehouses.write` |
| DELETE | `/api/v1/stocks/{id}` | `warehouses.delete` |
| GET | `/api/v1/stocks/{id}/children` | `warehouses.read` |
| GET | `/api/v1/stocks/{id}/audit` | `warehouses.audit.read` |
| POST | `/api/v1/stocks/{id}/external-meta` | `warehouses.write` |

Ответ узла включает:

```json
{
  "id": 1,
  "code": "wh-msk-01",
  "created_by": 42,
  "created_by_user": { "id": 42, "phone": "+7900...", "status": "active" },
  "type_label": "Склад (здание)"
}
```

Журнал узла (`/audit`):

```json
{
  "items": [
    {
      "event_type": "warehouses.stock.updated",
      "payload": { "stock_id": 1, "diff": { "name": { "from": "A", "to": "B" } } },
      "actor_user": { "id": 42, "phone": "+7900..." }
    }
  ]
}
```

## События аудита

- `warehouses.stock.created`
- `warehouses.stock.updated` (поле `diff`)
- `warehouses.stock.archived`
- `warehouses.stock.external_bound`

## Проверка

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/warehouses_journey_check.php
php maniforge/rbac/tools/check_all.php
```

## Дальше

- **Products** / **Inventory** — только если нужны остатки в Maniforge; иначе остатки остаются в enterprise, сюда — ссылки и meta.
