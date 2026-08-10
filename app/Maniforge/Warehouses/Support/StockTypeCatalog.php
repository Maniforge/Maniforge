<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Support;

/** Коды типов узлов дерева (совместимы с enterprise.local WMS). */
final class StockTypeCatalog
{
    public const WAREHOUSE = 'warehouse';
    public const ZONE = 'zone';
    public const RACK = 'rack';
    public const SHELF = 'shelf';
    public const CELL = 'cell';
    public const LOCATION = 'location';
    public const COMPANY = 'company';
    public const PRODUCTION = 'production';
    public const RETAIL_STORE = 'retail_store';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::WAREHOUSE => 'Склад',
            self::ZONE => 'Зона',
            self::RACK => 'Стеллаж',
            self::SHELF => 'Полка',
            self::CELL => 'Ячейка',
            self::LOCATION => 'Локация',
            self::COMPANY => 'Компания',
            self::PRODUCTION => 'Производство',
            self::RETAIL_STORE => 'Магазин',
            'ozon_fbo' => 'Ozon FBO',
            'ozon_fbs' => 'Ozon FBS',
            'wildberries_fbo' => 'Wildberries FBO',
            'wildberries_fbs' => 'Wildberries FBS',
        ];
    }
}
