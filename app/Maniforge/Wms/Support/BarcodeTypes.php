<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Support;

final class BarcodeTypes
{
    public const EAN13 = 'ean13';

    /** @return list<string> */
    public static function productBarcodes(): array
    {
        return [self::EAN13];
    }
}
