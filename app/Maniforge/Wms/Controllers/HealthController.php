<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Controllers;

final class HealthController
{
    public function __invoke(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'module' => 'wms',
            'features' => ['pack_units', 'marking_kiz', 'group_pack', 'pallet_sscc_qr', 'scan_movements'],
        ], JSON_UNESCAPED_UNICODE);
    }
}
