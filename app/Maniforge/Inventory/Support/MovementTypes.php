<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Support;

final class MovementTypes
{
    public const RECEIPT = 'receipt';
    public const ISSUE = 'issue';
    public const TRANSFER = 'transfer';
    public const ADJUSTMENT = 'adjustment';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::RECEIPT,
            self::ISSUE,
            self::TRANSFER,
            self::ADJUSTMENT,
        ];
    }
}
