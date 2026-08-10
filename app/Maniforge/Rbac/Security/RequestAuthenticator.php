<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Support\RequestContext;

/**
 * Единая точка проверки user session + step-up (action token или mfa_verified_at).
 */
final class RequestAuthenticator
{
    public function __construct(
        private readonly SessionService $sessions = new SessionService(),
        private readonly ActionTokenService $actionTokens = new ActionTokenService(),
        private readonly PolicyEngine $policies = new PolicyEngine(),
    ) {
    }

    public function authenticateSession(RequestContext $ctx): ?array
    {
        return $this->sessions->authenticate($ctx->bearerToken());
    }

    public function satisfiesSensitiveAction(RequestContext $ctx, array $session): bool
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];

        if (!$this->policies->requiresStepUp($tenantId, $subtenantId)) {
            return true;
        }

        $actionToken = $ctx->actionToken();
        if ($actionToken !== '' && $this->actionTokens->authenticate($actionToken, $session) !== null) {
            return true;
        }

        return $this->sessions->isStepUpFresh($session);
    }

    public function stepUpRequiredError(): array
    {
        return [
            'ok' => false,
            'error' => 'Требуется step-up: POST /api/v1/auth/reauth, затем X-Action-Token',
            'code' => 'step_up_required',
        ];
    }
}
