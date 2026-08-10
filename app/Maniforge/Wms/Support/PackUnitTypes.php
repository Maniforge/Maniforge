<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Support;

final class PackUnitTypes
{
    /** Единица с КИЗ (потребительская). */
    public const CONSUMER = 'consumer';

    /** Групповая упаковка (агрегация КИЗ). */
    public const GROUP = 'group';

    /** Паллета / логистическая единица. */
    public const PALLET = 'pallet';

    /** SSCC-логистическая метка (может совпадать с pallet). */
    public const SSCC = 'sscc';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CONSUMER, self::GROUP, self::PALLET, self::SSCC];
    }

    public static function canContain(string $parentType, string $childType): bool
    {
        return match ($parentType) {
            self::GROUP => in_array($childType, [self::CONSUMER], true)
                || $childType === self::GROUP,
            self::PALLET, self::SSCC => in_array($childType, [self::GROUP, self::CONSUMER, self::PALLET], true),
            default => false,
        };
    }
}
