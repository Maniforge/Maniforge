# Аудит систем учёта Maniforge Supply Chain

Обзор модулей, пробелов и реализованных улучшений (без внешних интеграций).

## Модули

| Модуль | Учёт | Зрелость |
|--------|------|----------|
| **Warehouses** | Иерархия узлов, scope, delegation | Production-ready для структуры склада |
| **Products** | SKU, scope, versioning | Production-ready для номенклатуры |
| **Inventory** | Остатки, движения, резервы, сторно | Расширен: available qty, summary, overview |
| **WMS** | КИЗ, ГУ, паллеты, скан → движения | Расширен: trace, transfer scan, batch/lot на строках |

## Точки роста (было → сделано)

| # | Пробел | Решение |
|---|--------|---------|
| 1 | Нет резервов под заказ/заявку | `maniforge_inv_reserves`, API `/reserves`, `qty_available` в balances |
| 2 | Issue не учитывал hold | `assertSufficientQty` проверяет on_hand − active reserves |
| 3 | Нет сводки остатков по SKU | `GET /inventory/api/v1/balances/summary` |
| 4 | Нет единой панели учёта | `GET /inventory/api/v1/reports/overview` |
| 5 | batch/lot только в БД | Проброс в flat POST movements |
| 6 | Transfer только flat API | WMS scan + `lines[]` для transfer |
| 7 | Нет трассировки КИЗ | `GET /wms/api/v1/markings/{id}/trace` |
| 8 | Сторно без отката WMS-статусов | `WmsMarkingLifecycle` при reverse |
| 9 | Нет EAN-13 на товаре | `barcode_ean13`, scan `kind=product`, приёмка по штрихкоду |

## Остаётся (сознательно)

- Черновики движений (`status=draft`) — обходится сторно.
- Заказы склада MVP: `POST /inventory/api/v1/orders` (confirm → резерв, fulfill → issue). ERP-интеграции нет.
- Партионный учёт только поля `batch_code`/`lot_code`, без отдельного регистра партий.
- Офлайн-сканер — в [MANIFORGE_WMS_SCANNER_UI.md](MANIFORGE_WMS_SCANNER_UI.md).

## API (новое)

```
GET  /inventory/api/v1/balances/summary
GET  /inventory/api/v1/reports/overview
GET  /inventory/api/v1/reserves
POST /inventory/api/v1/reserves
POST /inventory/api/v1/reserves/{id}/release
GET  /wms/api/v1/markings/{id}
GET  /wms/api/v1/markings/{id}/trace
POST /wms/api/v1/movements/scan  (movement_type: transfer + from/to)
```

## Проверка

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/supply_chain_growth_check.php
php maniforge/rbac/tools/check_all.php
```
