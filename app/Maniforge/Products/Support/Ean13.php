<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Support;

/** Нормализация и проверка EAN-13 (включая UPC-A 12 цифр → leading 0). */
final class Ean13
{
    /**
     * @return array{ok: bool, ean13?: string, error?: string}
     */
    public static function normalize(string $raw): array
    {
        $digits = preg_replace('/\D/', '', trim($raw)) ?? '';
        if ($digits === '') {
            return ['ok' => false, 'error' => 'Пустой штрихкод'];
        }

        if (strlen($digits) === 12) {
            $digits = '0' . $digits;
        }

        if (strlen($digits) !== 13) {
            return ['ok' => false, 'error' => 'EAN-13: ожидается 13 цифр (или 12 для UPC-A)'];
        }

        if (!ctype_digit($digits)) {
            return ['ok' => false, 'error' => 'EAN-13: только цифры'];
        }

        if (!self::isValidCheckDigit($digits)) {
            return ['ok' => false, 'error' => 'EAN-13: неверная контрольная цифра'];
        }

        return ['ok' => true, 'ean13' => $digits];
    }

    public static function isValidCheckDigit(string $ean13): bool
    {
        if (strlen($ean13) !== 13 || !ctype_digit($ean13)) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $ean13[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $ean13[12];
    }

    /** Похоже на штрихкод товара (не SSCC/КИЗ). */
    public static function looksLikeBarcode(string $raw): bool
    {
        $digits = preg_replace('/\D/', '', trim($raw)) ?? '';

        return strlen($digits) === 12 || strlen($digits) === 13;
    }
}
