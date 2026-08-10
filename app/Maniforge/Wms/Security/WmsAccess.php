<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Maniforge\Rbac\Security\RbacService;

final class WmsAccess
{
    public function __construct(
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    public function canRead(array $session): bool
    {
        return $this->has($session, 'wms.read');
    }

    public function canWrite(array $session): bool
    {
        return $this->has($session, 'wms.write');
    }

    private function has(array $session, string $permission): bool
    {
        return $this->rbac->hasPermission(
            (int) ($session['user_id'] ?? 0),
            (string) ($session['tenant_id'] ?? ''),
            (string) ($session['subtenant_id'] ?? ''),
            $permission
        );
    }
}
