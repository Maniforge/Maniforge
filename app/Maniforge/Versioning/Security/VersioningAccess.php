<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Security;

use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\TenantLicensingClient;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class VersioningAccess
{
    public function __construct(
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly TenantLicensingClient $licensing = new TenantLicensingClient(),
    ) {
    }

    public function guardRead(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'versioning.read');
    }

    public function guardRegistry(RequestContext $ctx): ?array
    {
        return $this->guard($ctx, 'versioning.registry.read');
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

        $feature = $this->assertSubscriptionFeature((string) $session['tenant_id'], (string) $session['subtenant_id']);
        if ($feature !== null) {
            JsonResponse::send($feature, (int) $feature['status']);
            return null;
        }

        return $session;
    }

    /**
     * Будущий feature-gate по тарифу: features.versioning в license plan.
     *
     * @return array{ok: false, status: int, error: string, code?: string}|null
     */
    private function assertSubscriptionFeature(string $tenantId, string $subtenantId): ?array
    {
        $enforce = strtolower(trim((string) ($_ENV['VERSIONING_FEATURE_ENFORCE'] ?? 'false')));
        if (!in_array($enforce, ['1', 'true', 'yes', 'on'], true)) {
            return null;
        }

        $access = $this->licensing->assertAccess($tenantId, $subtenantId);
        if (($access['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Лицензия недоступна'),
                'code' => 'license_required',
            ];
        }

        $state = is_array($access['state'] ?? null) ? $access['state'] : [];
        $features = is_array($state['features'] ?? null) ? $state['features'] : [];
        if (($features['versioning'] ?? false) === true) {
            return null;
        }

        return [
            'ok' => false,
            'status' => 402,
            'error' => 'История версий доступна по подписке versioning',
            'code' => 'versioning_subscription_required',
        ];
    }
}
