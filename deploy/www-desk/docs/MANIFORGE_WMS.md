# Maniforge WMS — упаковки, КИЗ, паллеты (SSCC/QR)

Модуль объединяет **маркировку (КИЗ)**, **групповую упаковку**, **паллеты с SSCC и QR**, **сканирование** и **проведение движений** через Inventory.

| Слой | Путь |
|------|------|
| URL | `/wms` |
| Код | `app/Maniforge/Wms` |
| Entry | `maniforge/wms/public/index.php` |

## Зависимости

- **Warehouses** — узлы `stock_id` для приёмки/отгрузки
- **Products** — `product_id` для КИЗ и строк движения
- **Inventory** — `maniforge_inv_balances` / `maniforge_inv_movements`; строки движения могут ссылаться на `pack_unit_id` и `marking_code_id`

## Таблицы (миграция 041)

| Таблица | Назначение |
|---------|------------|
| `maniforge_wms_pack_units` | consumer / group / pallet / sscc; `code`, `sscc`, `qr_payload`, `qr_lookup`, scope как у складов |
| `maniforge_wms_marking_codes` | КИЗ: `code_full`, разбор GTIN/serial/crypto, статус, привязка к упаковке |
| `maniforge_wms_pack_contents` | Иерархия: родитель → дочерняя упаковка или КИЗ |
| `maniforge_inv_movement_lines` | + `pack_unit_id`, `marking_code_id`, `batch_code`, `lot_code` |

## Permissions (042)

- `wms.read` — скан, просмотр упаковок
- `wms.write` — создание, агрегация, seal, движения по скану

## API

| Метод | Путь | Описание |
|-------|------|----------|
| GET | `/wms/health` | Health |
| POST | `/wms/api/v1/packs` | Создать упаковку (`unit_type`, `code`, опц. `sscc`, `stock_id`) |
| GET | `/wms/api/v1/packs` | Список (`unit_type`, `status`, `search`) |
| GET | `/wms/api/v1/packs/{id}` | Упаковка + contents + markings |
| DELETE | `/wms/api/v1/packs/{id}` | Удалить упаковку в статусе `draft` |
| POST | `/wms/api/v1/packs/{id}/markings` | Добавить КИЗ в group (draft) |
| POST | `/wms/api/v1/packs/{id}/children` | Вложить sealed group в pallet |
| POST | `/wms/api/v1/packs/{id}/seal` | Запечатать; для pallet — обновить QR |
| POST | `/wms/api/v1/packs/{id}/disaggregate` | Разобрать sealed/at_stock, КИЗ → `available` |
| GET | `/wms/api/v1/markings` | Список КИЗ (`product_id`, `status`, `search`) |
| POST | `/wms/api/v1/markings` | Зарегистрировать КИЗ (`product_id`, `code`) |
| POST | `/wms/api/v1/markings/bulk` | Массовая регистрация `codes[]` |
| GET/POST | `/wms/api/v1/scan` | SSCC / QR / упаковка / КИЗ / **EAN-13 товара** |
| GET | `/wms/api/v1/markings/{id}` | Карточка КИЗ |
| GET | `/wms/api/v1/markings/{id}/trace` | Журнал движений по КИЗ |
| POST | `/wms/api/v1/movements/scan` | `receipt`/`issue`/`transfer` по скану (+ `from_stock_id`/`to_stock_id` для transfer) |

Авторизация: Bearer/session как в RBAC; нужны `wms.*` и для движений — `inventory.write`.

## Типовой сценарий

1. Зарегистрировать КИЗ → `POST /markings`
2. Создать **group** → добавить КИЗ → **seal**
3. Создать **pallet** → добавить sealed group → **seal** (генерируется SSCC + QR)
4. **Приёмка**: `POST /movements/scan` с `pack_unit_id` или QR/SSCC паллеты → Inventory receipt, разворот дерева в строки с КИЗ
5. **Отгрузка по КИЗ**: scan одного кода → issue −1

## Проверка

```bash
php maniforge/rbac/tools/migrate.php
php maniforge/rbac/tools/wms_journey_check.php
```

## UI сканера (только спецификация)

Реализация фронтенда не в репозитории. Макеты экранов, потоки приёмки/отгрузки/сборки ГУ и матрица API: **[MANIFORGE_WMS_SCANNER_UI.md](MANIFORGE_WMS_SCANNER_UI.md)** (`/docs/maniforge-wms-scanner-ui.md`).

## Сторно движений

Обратное движение без внешних систем: `POST /inventory/api/v1/movements/{id}/reverse` — откат остатков, синхронизация статусов КИЗ через WMS.

См. также [MANIFORGE_SUPPLY_CHAIN_MODULES.md](MANIFORGE_SUPPLY_CHAIN_MODULES.md), [MANIFORGE_INVENTORY.md](MANIFORGE_INVENTORY.md).
