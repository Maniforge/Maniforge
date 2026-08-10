<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\TenantLicensingClient;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class WmsAccessGuard
{
    public function __construct(
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly WmsAccess $access = new WmsAccess(),
        private readonly TenantLicensingClient $licensing = new TenantLicensingClient(),
    ) {
    }

    public function guardRead(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, true);
    }

    public function guardWrite(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, false);
    }

    private function guard(RequestContext $ctx, bool $readOnly): ?array
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);

            return null;
        }

        $allowed = $readOnly ? $this->access->canRead($session) : $this->access->canWrite($session);
        if (!$allowed) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно permissions'], 403);

            return null;
        }

        $lic = $this->licensing->assertAccess(
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if (($lic['ok'] ?? false) !== true) {
            JsonResponse::send([
                'ok' => false,
                'error' => (string) ($lic['error'] ?? 'Лицензия'),
            ], (int) ($lic['status'] ?? 403));

            return null;
        }

        return $session;
    }
}
