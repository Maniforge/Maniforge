<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Support;

/** Генерация SSCC и QR payload для паллет / групповых упаковок Maniforge WMS. */
final class QrSsccGenerator
{
    public static function sscc(string $extensionDigit = '0', string $companyPrefix = '4600001'): string
    {
        $prefix = preg_replace('/\D/', '', $companyPrefix) ?? '';
        $prefix = str_pad(substr($prefix, 0, 7), 7, '0', STR_PAD_LEFT);
        $serial = str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $body = $extensionDigit . $prefix . $serial;
        $check = self::gs1CheckDigit($body);

        return $body . $check;
    }

    /**
     * @param array<string, mixed> $pack
     */
    public static function qrPayload(array $pack): string
    {
        return json_encode([
            'v' => 1,
            'kind' => 'maniforge_wms_pack',
            'tenant_id' => (string) ($pack['tenant_id'] ?? ''),
            'pack_id' => (int) ($pack['id'] ?? 0),
            'unit_type' => (string) ($pack['unit_type'] ?? ''),
            'code' => (string) ($pack['code'] ?? ''),
            'sscc' => $pack['sscc'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function qrLookup(string $payload): string
    {
        return hash('sha256', $payload);
    }

    private static function gs1CheckDigit(string $digits): int
    {
        $sum = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $n = (int) $digits[$len - 1 - $i];
            $sum += ($i % 2 === 0) ? $n * 3 : $n;
        }
        $mod = $sum % 10;

        return $mod === 0 ? 0 : 10 - $mod;
    }
}
