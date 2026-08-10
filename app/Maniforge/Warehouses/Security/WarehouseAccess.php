<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Security;

use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\TenantLicensingClient;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class WarehouseAccess
{
    public function __construct(
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly TenantLicensingClient $licensing = new TenantLicensingClient(),
    ) {
    }

    public function guardRead(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'warehouses.read');
    }

    public function guardWrite(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'warehouses.write');
    }

    public function guardDelete(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'warehouses.delete');
    }

    public function guardTypes(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'warehouses.types.read');
    }

    public function guardAuditRead(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'warehouses.audit.read');
    }

    private function guard(RequestContext $ctx, string $permission): ?array
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return null;
        }

        if (!$this->rbac->hasPermission(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $permission
        )) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно permissions'], 403);
            return null;
        }

        $access = $this->licensing->assertAccess(
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if (($access['ok'] ?? false) !== true) {
            JsonResponse::send([
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Лицензия недоступна'),
            ], (int) ($access['status'] ?? 403));
            return null;
        }

        return $session;
    }
}
