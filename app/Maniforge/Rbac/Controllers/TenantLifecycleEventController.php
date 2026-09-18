<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\RefreshTokenRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class TenantLifecycleEventController
{
    public function __construct(
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
    ) {
    }

    public function receive(RequestContext $ctx): void
    {
        if (!$this->guardInternal($ctx)) {
            return;
        }

        $eventType = trim((string) ($ctx->input['event_type'] ?? ''));
        $tenantCode = strtolower(trim((string) ($ctx->input['tenant_code'] ?? '')));
        $subtenantCode = strtolower(trim((string) ($ctx->input['subtenant_code'] ?? '')));
        $payload = is_array($ctx->input['payload'] ?? null) ? $ctx->input['payload'] : [];
        if ($eventType === '' || $tenantCode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'event_type и tenant_code обязательны'], 422);
            return;
        }

        $revokingEvents = [
            'tenant.suspended',
            'tenant.disabled',
            'subtenant.suspended',
            'subtenant.disabled',
            'license.revoked',
            'license.expired',
        ];
        $revokedSessions = 0;
        $revokedRefreshTokens = 0;
        if (in_array($eventType, $revokingEvents, true)) {
            $scopeSubtenant = str_starts_with($eventType, 'subtenant.') ? $subtenantCode : null;
            $reason = 'tenant_lifecycle:' . $eventType;
            $revokedSessions = $this->sessions->revokeAllInTenant($tenantCode, $scopeSubtenant, $reason);
            $revokedRefreshTokens = $this->refreshTokens->revokeAllInTenant($tenantCode, $scopeSubtenant, $reason);
        }

        $auditPayload = [
            'event_type' => $eventType,
            'source_payload' => $payload,
            'revoked_sessions' => $revokedSessions,
            'revoked_refresh_tokens' => $revokedRefreshTokens,
        ];
        $this->audit->write('tenant_lifecycle.event.received', null, $tenantCode, $subtenantCode ?: 'all', $auditPayload);
        $this->securityEvents->write(
            'tenant_lifecycle.event.processed',
            null,
            $tenantCode,
            $subtenantCode ?: 'all',
            $revokedSessions > 0 ? 'warning' : 'info',
            $auditPayload
        );

        JsonResponse::send([
            'ok' => true,
            'event_type' => $eventType,
            'revoked_sessions' => $revokedSessions,
            'revoked_refresh_tokens' => $revokedRefreshTokens,
        ]);
    }

    private function guardInternal(RequestContext $ctx): bool
    {
        $tokens = array_values(array_filter(array_unique([
            trim((string) ($_ENV['RBAC_INTERNAL_TOKEN'] ?? '')),
            trim((string) ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? '')),
        ])));
        if ($tokens === []) {
            $env = strtolower((string) ($_ENV['APP_ENV'] ?? 'production'));
            if (in_array($env, ['local', 'testing', 'test'], true)) {
                return true;
            }

            JsonResponse::send(['ok' => false, 'error' => 'Internal token не настроен'], 503);
            return false;
        }

        $provided = $ctx->bearerToken();
        foreach ($tokens as $expected) {
            if (hash_equals($expected, $provided)) {
                return true;
            }
        }

        JsonResponse::send(['ok' => false, 'error' => 'Недостаточно прав'], 403);
        return false;
    }
}
