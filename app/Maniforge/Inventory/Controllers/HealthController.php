<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;

final class HealthController
{
    public function __invoke(): void
    {
        JsonResponse::send([
            'ok' => true,
            'service' => 'maniforge-inventory',
            'status' => 'up',
            'timestamp' => gmdate('c'),
        ]);
    }
}
