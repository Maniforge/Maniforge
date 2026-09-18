<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Support;

final class MarkingCodeTypes
{
    public const KIZ = 'kiz';
    public const DATAMATRIX = 'datamatrix';
    public const QR = 'qr';
    public const BARCODE = 'barcode';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::KIZ, self::DATAMATRIX, self::QR, self::BARCODE];
    }
}
