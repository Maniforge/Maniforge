<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Support\EntityScope;
use App\Maniforge\Rbac\Repository\RefreshTokenRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Repository\UserRepository;

final class SessionService
{
    public function __construct(
        private readonly SessionRepository $sessions = new SessionRepository(),
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly TenantLicensingClient $tenantLicensing = new TenantLicensingClient(),
        private readonly TenantPdComplianceService $pdCompliance = new TenantPdComplianceService(),
        private readonly ActionTokenService $actionTokens = new ActionTokenService(),
    ) {
    }

    public function issue(array $user, array $tenant, array $server): array
    {
        $token = bin2hex(random_bytes(32));
        $ttlMinutes = (int) ($_ENV['RBAC_SESSION_TTL_MINUTES'] ?? 720);
        $sessionId = bin2hex(random_bytes(16));
        $defaultProject = (new DefaultProjectService())->ensureDefault(
            (string) $tenant['tenant_id'],
            (string) $tenant['subtenant_id']
        );
        $projectId = (int) ($defaultProject['id'] ?? 0);
        if ($projectId <= 0) {
            $projectId = null;
        }

        $this->sessions->create([
            ':id' => $sessionId,
            ':user_id' => (int) $user['id'],
            ':tenant_id' => $tenant['tenant_id'],
            ':subtenant_id' => $tenant['subtenant_id'],
            ':project_id' => $projectId,
            ':session_secret_hash' => hash('sha256', $token),
            ':ip_hash' => hash('sha256', (string) ($server['REMOTE_ADDR'] ?? 'unknown')),
            ':user_agent_hash' => hash('sha256', (string) ($server['HTTP_USER_AGENT'] ?? 'unknown')),
            ':aal' => $user['mfa_required'] ? 'AAL2' : 'AAL1',
            ':last_activity_at' => gmdate('Y-m-d H:i:s'),
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60)),
            ':security_version_snapshot' => (int) ($user['security_version'] ?? 1),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $refreshToken = bin2hex(random_bytes(32));
        $refreshTtlDays = (int) ($_ENV['RBAC_REFRESH_TTL_DAYS'] ?? 30);
        $this->refreshTokens->create([
            ':id' => bin2hex(random_bytes(16)),
            ':session_id' => $sessionId,
            ':user_id' => (int) $user['id'],
            ':tenant_id' => $tenant['tenant_id'],
            ':subtenant_id' => $tenant['subtenant_id'],
            ':project_id' => $projectId,
            ':token_hash' => hash('sha256', $refreshToken),
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + ($refreshTtlDays * 86400)),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'credential_type' => 'session_access',
            'session_id' => $sessionId,
            'access_token' => $token,
            'refresh_token' => $refreshToken,
            'expires_in' => $ttlMinutes * 60,
            'user_id' => (int) $user['id'],
            'tenant_id' => (string) $tenant['tenant_id'],
            'subtenant_id' => (string) $tenant['subtenant_id'],
            'project_id' => $projectId,
            'project_code' => EntityScope::DEFAULT_PROJECT_CODE,
            'scope' => [
                'tenant_id' => (string) $tenant['tenant_id'],
                'subtenant_id' => (string) $tenant['subtenant_id'],
                'project_id' => $projectId,
                'project_code' => EntityScope::DEFAULT_PROJECT_CODE,
            ],
        ];
    }

    public function switchProject(array $session, ?int $projectId): array
    {
        $projectService = new ProjectService();
        $check = $projectService->switchProject($session, $projectId);
        if (!($check['ok'] ?? false)) {
            return $check;
        }

        if (($check['session']['unchanged'] ?? false) === true) {
            return $check;
        }

        $bound = $this->sessions->rebindProject((string) $session['id'], $projectId);
        if (!$bound) {
            return ['ok' => false, 'status' => 500, 'error' => 'Не удалось переключить проект сессии'];
        }

        return $check;
    }

    public function authenticate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $session = $this->sessions->findActiveByToken($token);
        if ($session === null) {
            return null;
        }

        $user = $this->users->findByIdInScope(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if ($user === null) {
            $homeUser = $this->users->findById((int) $session['user_id']);
            if ($homeUser === null) {
                $this->revokeSessionCredentials((string) $session['id'], 'user_not_active');
                return null;
            }
            $allowed = (new ContextService())->isContextAllowed(
                trim((string) ($homeUser['phone'] ?? '')),
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            );
            if (($allowed['ok'] ?? false) !== true) {
                $this->revokeSessionCredentials((string) $session['id'], 'delegated_context_denied');
                return null;
            }
            $user = $homeUser;
        }
        $securityVersionChanged = $user !== null
            && (int) ($user['security_version'] ?? 0) !== (int) ($session['security_version_snapshot'] ?? 0);
        if ($user === null || ($user['status'] ?? 'active') !== 'active' || $securityVersionChanged) {
            $reason = $securityVersionChanged ? 'security_version_changed' : 'user_not_active';
            $this->revokeSessionCredentials((string) $session['id'], $reason);
            return null;
        }

        $access = $this->tenantLicensing->assertAccess(
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if (!$access['ok']) {
            if (($access['temporary'] ?? false) !== true) {
                $reason = 'tenant_license_denied:' . (string) ($access['deny_reason'] ?? 'unknown');
                $this->revokeSessionCredentials((string) $session['id'], $reason);
            }
            return null;
        }

        $compliance = $this->pdCompliance->assertLoginAllowed(
            (string) $session['tenant_id'],
            (int) $session['user_id'],
            (string) $session['subtenant_id']
        );
        if (($compliance['ok'] ?? false) !== true) {
            $this->revokeSessionCredentials(
                (string) $session['id'],
                'pd_compliance:' . (string) ($compliance['deny_reason'] ?? 'blocked')
            );
            return null;
        }

        $this->sessions->touch((string) $session['id']);

        return $session;
    }

    public function revokeByToken(string $token, string $reason): bool
    {
        $session = $this->sessions->findActiveByToken($token);
        if ($session === null) {
            return false;
        }

        $this->revokeSessionCredentials((string) $session['id'], $reason);
        return true;
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        $revokedSessions = $this->sessions->revokeAllUserSessions($userId, $reason);
        $this->refreshTokens->revokeAllForUser($userId, $reason);
        $this->actionTokens->revokeAllForUser($userId);
        return $revokedSessions;
    }

    public function refresh(string $refreshToken, array $server): array
    {
        if ($refreshToken === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'refresh_token обязателен'];
        }

        $row = $this->refreshTokens->findActiveByToken($refreshToken);
        if ($row === null) {
            return ['ok' => false, 'status' => 401, 'error' => 'Недействительный refresh token'];
        }

        $tenant = [
            'tenant_id' => (string) $row['tenant_id'],
            'subtenant_id' => (string) $row['subtenant_id'],
        ];
        $user = $this->users->findByIdInScope(
            (int) $row['user_id'],
            $tenant['tenant_id'],
            $tenant['subtenant_id']
        );
        $parentSession = $this->sessions->findById((string) $row['session_id']);
        $securityVersionChanged = $user !== null
            && $parentSession !== null
            && (int) ($user['security_version'] ?? 0) !== (int) ($parentSession['security_version_snapshot'] ?? 0);
        if ($user === null || $parentSession === null || ($user['status'] ?? 'active') !== 'active' || $securityVersionChanged) {
            $this->refreshTokens->revokeById((string) $row['id'], 'user_not_allowed');
            return ['ok' => false, 'status' => 403, 'error' => 'Пользователь недоступен'];
        }

        $access = $this->tenantLicensing->assertAccess($tenant['tenant_id'], $tenant['subtenant_id']);
        if (!$access['ok']) {
            if (($access['temporary'] ?? false) !== true) {
                $this->refreshTokens->revokeById(
                    (string) $row['id'],
                    'tenant_license_denied:' . (string) ($access['deny_reason'] ?? 'unknown')
                );
            }
            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Tenant недоступен'),
            ];
        }

        $compliance = $this->pdCompliance->assertLoginAllowed(
            $tenant['tenant_id'],
            (int) $user['id'],
            $tenant['subtenant_id']
        );
        if (($compliance['ok'] ?? false) !== true) {
            $this->refreshTokens->revokeById(
                (string) $row['id'],
                'pd_compliance:' . (string) ($compliance['deny_reason'] ?? 'blocked')
            );
            return [
                'ok' => false,
                'status' => (int) ($compliance['status'] ?? 403),
                'error' => (string) ($compliance['error'] ?? 'Compliance не выполнен'),
            ];
        }

        $this->revokeSessionCredentials((string) $row['session_id'], 'rotated');
        $newSession = $this->issue($user, $tenant, $server);
        $this->refreshTokens->revokeById((string) $row['id'], 'rotated');

        return [
            'ok' => true,
            'status' => 200,
            'session' => $newSession,
        ];
    }

    public function markStepUp(string $accessToken): bool
    {
        $session = $this->authenticate($accessToken);
        if ($session === null) {
            return false;
        }

        $this->sessions->markMfaVerified((string) $session['id']);
        return true;
    }

    public function isStepUpFresh(array $session): bool
    {
        if (($session['mfa_verified_at'] ?? null) === null) {
            return false;
        }

        $maxAgeSec = (int) ($_ENV['RBAC_MFA_STEPUP_MAX_AGE_SEC'] ?? 900);
        try {
            $verifiedAt = new \DateTimeImmutable(
                (string) $session['mfa_verified_at'],
                new \DateTimeZone('UTC')
            );
        } catch (\Exception) {
            return false;
        }

        return (time() - $verifiedAt->getTimestamp()) <= $maxAgeSec;
    }

    private function revokeSessionCredentials(string $sessionId, string $reason): void
    {
        $this->sessions->revoke($sessionId, $reason);
        $this->refreshTokens->revokeBySessionId($sessionId, $reason);
        $this->actionTokens->revokeForSession($sessionId);
    }
}
