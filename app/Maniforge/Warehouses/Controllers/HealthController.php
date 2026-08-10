<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;

final class HealthController
{
    public function __invoke(): void
    {
        JsonResponse::send([
            'ok' => true,
            'service' => 'maniforge-warehouses',
            'status' => 'up',
            'module' => 'warehouses',
            'platform' => [
                'audit' => 'maniforge_audit_log',
                'actors' => 'maniforge_users',
                'versioning' => 'maniforge_ver_changes',
                'rbac' => 'warehouses.*',
            ],
            'timestamp' => gmdate('c'),
        ]);
    }
}
