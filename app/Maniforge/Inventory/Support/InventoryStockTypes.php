<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Support;

use App\Maniforge\Warehouses\Support\StockTypeCatalog;

/** Типы узлов WMS, на которых допустим учёт остатков. */
final class InventoryStockTypes
{
    /** @return list<string> */
    public static function allowed(): array
    {
        return [
            StockTypeCatalog::WAREHOUSE,
            StockTypeCatalog::ZONE,
            StockTypeCatalog::RACK,
            StockTypeCatalog::SHELF,
            StockTypeCatalog::CELL,
            StockTypeCatalog::LOCATION,
        ];
    }

    public static function isAllowed(string $type): bool
    {
        return in_array(strtolower(trim($type)), self::allowed(), true);
    }
}
