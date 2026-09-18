# Maniforge Products — номенклатура

Модуль товаров (SKU) в scope **tenant + project**, с той же моделью видимости и delegation share, что и Warehouses.

> Термины: [MANIFORGE_GLOSSARY.md](MANIFORGE_GLOSSARY.md), scope: [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md).

## Принцип

| Слой | Где |
|------|-----|
| Данные | `maniforge_products` |
| Scope | `tenant_id`, `subtenant_id`, `project_id`, `scope_visibility` |
| Доступ principal ↔ managed | `delegation_share_tenant_ids` / флаги API |
| Аудит | `products.product.*` в `maniforge_audit_log` |
| Версии | `maniforge_ver_changes` (`maniforge_products`) |
| Внешние ключи | `maniforge_entity_meta` (`i_index` = product) |

## API

| Метод | Путь |
|-------|------|
| GET | `/products/health` |
| GET | `/products/api/v1/products` |
| POST | `/products/api/v1/products` |
| GET | `/products/api/v1/products/{id}` |
| PATCH | `/products/api/v1/products/{id}` |
| DELETE | `/products/api/v1/products/{id}` (архив) |
| POST | `/products/api/v1/products/{id}/external-meta` |
| GET | `/products/api/v1/delegation/grant-peers` |

Permissions: `products.read`, `products.write`, `products.delete`.

## Создание

По умолчанию — **project** + текущий `project_id` сессии. Поля scope как у складов: `scope_visibility`, `tenant_level`, `target_subtenant_id`, `shared_subtenant_ids`, `share_with_principal`, …

Тело:

- `code` — SKU (уникален в scope; иначе автоген `sku-…`)
- `name` — обязательно
- `unit` — по умолчанию `pcs`
- `barcode_ean13` / `ean13` / `barcode: {type:"ean13", value:"..."}` — **EAN-13** (12 цифр UPC-A дополняются до 13), проверка контрольной суммы GS1
- `description`, `attributes` (объект)

## Штрихкод EAN-13

| Метод | Путь |
|-------|------|
| GET | `/products/api/v1/products/by-barcode/{ean13}` |

Уникальность: `(tenant_id, barcode_ean13)`. Скан в WMS: `kind=product` → приёмка/отгрузка с `qty`.

## Связи (следующие итерации)

- Inventory — остатки по складу
- Привязка SKU к узлам WMS через `entity_meta` или отдельные связи

## Проверка

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/products_journey_check.php
```
