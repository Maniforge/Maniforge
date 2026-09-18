<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;

final class HealthController
{
    public function __invoke(array $tenant): void
    {
        JsonResponse::send([
            'ok' => true,
            'service' => 'maniforge-rbac',
            'status' => 'up',
            'tenancy_mode' => $tenant['mode'],
            'timestamp' => gmdate('c'),
        ]);
    }
}
