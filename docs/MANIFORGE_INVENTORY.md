# Maniforge Inventory — остатки и движения

Модуль закрывает цепочку **Warehouses + Products → остаток на узле**.

> Scope и delegation: [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md). Склады: [MANIFORGE_WAREHOUSES.md](MANIFORGE_WAREHOUSES.md). Товары: [MANIFORGE_PRODUCTS.md](MANIFORGE_PRODUCTS.md).

## Архитектурное решение

| Слой | Таблица | Роль |
|------|---------|------|
| **Остаток (balance)** | `maniforge_inv_balances` | Материализованное состояние `(tenant, product, stock) → qty` |
| **Движение (movement)** | `maniforge_inv_movements` + `maniforge_inv_movement_lines` | Неизменяемый журнал; проведение в одной транзакции обновляет balances |

**Почему так:** движения — источник правды для аудита; остатки — быстрый read без суммирования всей истории.

### Видимость

- **Движения** несут scope документа (`tenant_id`, `project_id`, `scope_visibility`, delegation share) — как Products.
- **Остатки** не дублируют scope: видны, если **и товар, и узел склада** видны в сессии (двойной JOIN visibility).

### Узлы для учёта

Только типы WMS: `warehouse`, `zone`, `rack`, `shelf`, `cell`, `location` (см. `InventoryStockTypes`).

## Типы движений

| type | Эффект |
|------|--------|
| `receipt` | Приход (+qty) |
| `issue` | Расход (−qty), проверка остатка |
| `transfer` | `from_stock` −qty, `to_stock` +qty (один product) |
| `adjustment` | `qty_delta` или целевой `qty_after` |

По умолчанию проведение сразу (`status=posted`). Черновик: `status: draft` или `post_immediately: false` → `POST .../movements/{id}/post`, отмена `DELETE .../movements/{id}`.

## API

| Метод | Путь |
|-------|------|
| GET | `/inventory/health` |
| GET | `/inventory/api/v1/balances` |
| GET | `/inventory/api/v1/balances/summary` |
| GET | `/inventory/api/v1/reports/overview` |
| GET | `/inventory/api/v1/reserves` |
| POST | `/inventory/api/v1/reserves` |
| POST | `/inventory/api/v1/reserves/{id}/release` |
| GET | `/inventory/api/v1/movements` |
| POST | `/inventory/api/v1/movements` |
| GET | `/inventory/api/v1/movements/{id}` |
| POST | `/inventory/api/v1/movements/{id}/reverse` |
| POST | `/inventory/api/v1/movements/{id}/post` |
| DELETE | `/inventory/api/v1/movements/{id}` |
| GET | `/inventory/api/v1/lots` |
| POST | `/inventory/api/v1/lots` |
| GET | `/inventory/api/v1/lots/{id}` |
| GET | `/inventory/api/v1/orders` |
| POST | `/inventory/api/v1/orders` |
| GET | `/inventory/api/v1/orders/{id}` |
| POST | `/inventory/api/v1/orders/{id}/confirm` |
| POST | `/inventory/api/v1/orders/{id}/fulfill` |
| POST | `/inventory/api/v1/orders/{id}/cancel` |
| GET | `/inventory/api/v1/delegation/grant-peers` |

Permissions: `inventory.read`, `inventory.write`.

### Примеры

Приход:

```json
POST /inventory/api/v1/movements
{
  "movement_type": "receipt",
  "product_id": 1,
  "stock_id": 2,
  "qty": "100",
  "doc_number": "rcv-2026-001"
}
```

Перемещение:

```json
{
  "movement_type": "transfer",
  "product_id": 1,
  "from_stock_id": 2,
  "to_stock_id": 5,
  "qty": "10"
}
```

## Резервы и заказы

`maniforge_inv_reserves` — hold qty под `ref_code`.

- Заказы (`maniforge_inv_orders`): `confirm` создаёт резервы с `ref_code=inv-order:{order_number}`, `fulfill` снимает резервы и проводит `issue`.
- В списке остатков: `qty`, `qty_reserved`, `qty_available`.
- Issue/transfer учитывают **available**, не только on_hand.

## Партии (lots)

`maniforge_inv_lots` — уникальная пара `(batch_code, lot_code)` на товар. В строках движения — `lot_id` (и текстовые `batch_code`/`lot_code`).

## Products: остатки в карточке

`GET /products/api/v1/products/{id}?include=balances` — вложенный список остатков по узлам.

## Ограничения

- Отрицательный остаток запрещён (`insufficient_qty`).
- Сторно: `POST .../movements/{id}/reverse` — обратные строки + откат статусов КИЗ (WMS).

## Проверка

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/inventory_journey_check.php
php maniforge/rbac/tools/supply_chain_growth_check.php
```
