<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Support;

/** Разбор КИЗ / GS1 DataMatrix (упрощённо, без онлайн-проверки Честный ЗНАК). */
final class KizParser
{
    /**
     * @return array{ok: bool, gtin?: string, serial?: string, crypto_tail?: string, error?: string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return ['ok' => false, 'error' => 'Пустой код'];
        }

        if (preg_match('/\(01\)(\d{14})/', $raw, $m) === 1) {
            $gtin = $m[1];
            $serial = null;
            if (preg_match('/\(21\)([^\(\]]+)/', $raw, $s) === 1) {
                $serial = trim($s[1]);
            }
            $crypto = null;
            if (preg_match('/\(93\)([A-Za-z0-9]{4,44})/', $raw, $c) === 1) {
                $crypto = $c[1];
            }

            return [
                'ok' => true,
                'gtin' => $gtin,
                'serial' => $serial,
                'crypto_tail' => $crypto,
            ];
        }

        if (strlen($raw) >= 20 && strlen($raw) <= 255) {
            return [
                'ok' => true,
                'gtin' => null,
                'serial' => substr($raw, 0, 50),
                'crypto_tail' => strlen($raw) > 50 ? substr($raw, -44) : null,
            ];
        }

        return ['ok' => false, 'error' => 'Нераспознанный формат КИЗ'];
    }
}
