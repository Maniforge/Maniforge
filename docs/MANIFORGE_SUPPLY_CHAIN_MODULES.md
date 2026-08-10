# Maniforge Supply Chain — Warehouses, Products, Inventory, WMS

| Модуль | URL prefix | Код (`app/Maniforge`) | Данные (`maniforge/`) |
|--------|------------|-----------------|-----------------|
| **Warehouses** | `/warehouses` | `App\Maniforge\Warehouses` | `maniforge/warehouses/` |
| **Products** | `/products` | `App\Maniforge\Products` | `maniforge/products/` |
| **Inventory** | `/inventory` | `App\Maniforge\Inventory` | `maniforge/inventory/` |
| **WMS** | `/wms` | `App\Maniforge\Wms` | `maniforge/wms/` |

Миграции supply-chain — в `maniforge/rbac/migrations/` (общий `migrate.php`).

## Статус

| Модуль | БД + API | Scope + delegation |
|--------|----------|-------------------|
| Warehouses | ✅ | ✅ |
| Products | ✅ | ✅ |
| Inventory | ✅ | ✅ (movements); balances via product+stock visibility |
| WMS | ✅ | ✅ (pack units scope); КИЗ + паллеты + scan → Inventory |

## Цепочка данных

```text
Warehouses (stock)  +  Products (SKU)
            ↓
    WMS: КИЗ → group → pallet (SSCC/QR)
            ↓
    maniforge_inv_balances (остаток)
            ↑
    maniforge_inv_movements (журнал, строки с pack_unit_id / marking_code_id)
```

Спецификации: [MANIFORGE_WAREHOUSES.md](MANIFORGE_WAREHOUSES.md), [MANIFORGE_PRODUCTS.md](MANIFORGE_PRODUCTS.md), [MANIFORGE_INVENTORY.md](MANIFORGE_INVENTORY.md), [MANIFORGE_WMS.md](MANIFORGE_WMS.md), [MANIFORGE_ENTITY_SCOPE.md](MANIFORGE_ENTITY_SCOPE.md).

Аудит и точки роста: [MANIFORGE_SUPPLY_CHAIN_AUDIT.md](MANIFORGE_SUPPLY_CHAIN_AUDIT.md).
