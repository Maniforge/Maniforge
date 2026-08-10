<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Security\ActionTokenService;
use App\Maniforge\Rbac\Security\AuthService;
use App\Maniforge\Rbac\Security\ContextService;
use App\Maniforge\Rbac\Security\PasswordService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Security\UserOrganizationService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\SessionService;
use App\Maniforge\Rbac\Security\UserSecurityService;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\PublicUserPayload;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly SessionService $sessions = new SessionService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly UserSecurityService $userSecurity = new UserSecurityService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly ContextService $contexts = new ContextService(),
        private readonly ActionTokenService $actionTokens = new ActionTokenService(),
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly RegistrationService $registration = new RegistrationService(),
        private readonly UserOrganizationService $organizations = new UserOrganizationService(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
    ) {
    }

    public function register(RequestContext $ctx): void
    {
        $result = $this->registration->register($ctx->input, $ctx->server);
        if (($result['ok'] ?? false) === true && isset($result['user']) && is_array($result['user'])) {
            $result['user'] = PublicUserPayload::fromUser($result['user']);
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function acceptInvite(RequestContext $ctx): void
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $result = $this->organizations->acceptInvite(
            $session,
            (string) ($ctx->input['invite_token'] ?? '')
        );
        if (($result['ok'] ?? false) === true && isset($result['user']) && is_array($result['user'])) {
            $result['user'] = PublicUserPayload::fromUser($result['user']);
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function login(RequestContext $ctx): void
    {
        $result = $this->auth->login($ctx->tenant, $ctx->input, $ctx->server);
        if (($result['ok'] ?? false) === true) {
            $result['csrf_token'] = (string) ($_SESSION['csrf_token'] ?? '');
        }

        JsonResponse::send($result, (int) $result['status']);
    }

    public function me(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $sessionPayload = [
            'id' => $session['id'],
            'user_id' => (int) $session['user_id'],
            'tenant_id' => $session['tenant_id'],
            'subtenant_id' => $session['subtenant_id'],
            'project_id' => isset($session['project_id']) && $session['project_id'] !== null && $session['project_id'] !== ''
                ? (int) $session['project_id']
                : null,
            'aal' => $session['aal'],
            'expires_at' => $session['expires_at'],
        ];

        $ctxResult = $this->contexts->contextsForSession($session);
        $current = $ctxResult['current'] ?? null;
        if (is_array($current)) {
            foreach (['kind', 'delegated', 'grant_level', 'principal_tenant_id'] as $field) {
                if (array_key_exists($field, $current)) {
                    $sessionPayload[$field] = $current[$field];
                }
            }
        }

        JsonResponse::send([
            'ok' => true,
            'session' => $sessionPayload,
        ]);
    }

    public function logout(RequestContext $ctx): void
    {
        $token = $ctx->bearerToken();
        if ($token === '') {
            JsonResponse::send(['ok' => false, 'error' => 'Bearer token обязателен'], 401);
            return;
        }

        $revoked = $this->sessions->revokeByToken($token, 'manual_logout');
        JsonResponse::send(['ok' => $revoked], $revoked ? 200 : 404);
    }

    public function logoutAll(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $count = $this->sessions->revokeAllForUser((int) $session['user_id'], 'logout_all');
        $this->audit->write(
            'auth.logout_all',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['revoked_sessions' => $count]
        );
        JsonResponse::send(['ok' => true, 'revoked_sessions' => $count]);
    }

    public function refresh(RequestContext $ctx): void
    {
        $result = $this->sessions->refresh((string) ($ctx->input['refresh_token'] ?? ''), $ctx->server);
        if (($result['ok'] ?? false) === true) {
            $this->securityEvents->write(
                'auth.refresh.success',
                null,
                (string) $ctx->tenant['tenant_id'],
                (string) $ctx->tenant['subtenant_id'],
                'info',
                []
            );
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function reauth(RequestContext $ctx): void
    {
        $token = $ctx->bearerToken();
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $password = (string) ($ctx->input['password'] ?? '');
        $user = $this->users->findByIdForSession($session);
        if ($user === null || !$this->passwords->verify($password, (string) $user['password_hash'])) {
            JsonResponse::send(['ok' => false, 'error' => 'Неверный пароль re-auth'], 403);
            return;
        }

        $ok = $this->sessions->markStepUp($token);
        if (!$ok) {
            JsonResponse::send(['ok' => false, 'step_up' => false], 500);
            return;
        }

        $action = $this->actionTokens->issueForSession($session);
        $this->securityEvents->write(
            'auth.reauth.success',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            'info',
            ['action_token_issued' => true]
        );

        JsonResponse::send([
            'ok' => true,
            'step_up' => true,
            'credentials' => [
                'action' => array_merge(['credential_type' => 'action'], $action),
            ],
        ]);
    }

    public function profile(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $userId = (int) $session['user_id'];
        $user = $this->users->findByIdInScope($userId, $tenantId, $subtenantId);
        if ($user === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Пользователь не найден'], 404);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'user' => PublicUserPayload::fromUser($user),
            'roles' => $this->rbac->rolesForUser($userId, $tenantId, $subtenantId),
            'session' => [
                'aal' => $session['aal'] ?? null,
                'expires_at' => $session['expires_at'] ?? null,
            ],
        ]);
    }

    public function updateProfile(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $userId = (int) $session['user_id'];
        $changes = [];
        if (array_key_exists('email', $ctx->input)) {
            $email = trim((string) $ctx->input['email']);
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                JsonResponse::send(['ok' => false, 'error' => 'Некорректный email'], 422);
                return;
            }
            $changes['email'] = strtolower($email);
        }
        if (array_key_exists('phone', $ctx->input) || array_key_exists('phone_prefix', $ctx->input)) {
            $phone = $this->registration->resolvePhoneFromInput($ctx->input);
            if ($phone === '' || !str_starts_with($phone, '+') || preg_match('/^\+\d{10,15}$/', $phone) !== 1) {
                JsonResponse::send([
                    'ok' => false,
                    'error' => 'Телефон: укажите код страны и номер (10–15 цифр в международном формате)',
                ], 422);
                return;
            }
            $changes['phone'] = $phone;
        }

        if ($changes === []) {
            JsonResponse::send(['ok' => false, 'error' => 'Укажите email и/или phone для обновления'], 422);
            return;
        }

        $before = $this->users->findByIdInScope($userId, $tenantId, $subtenantId);

        try {
            $user = $this->users->updateUserInScope($userId, $tenantId, $subtenantId, $changes);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                JsonResponse::send(['ok' => false, 'error' => 'Email уже используется в этом scope'], 409);
                return;
            }
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка обновления профиля'], 500);
            return;
        }

        if ($user === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Пользователь не найден'], 404);
            return;
        }

        $this->audit->write('auth.profile.updated', $userId, $tenantId, $subtenantId, $changes);
        if ($before !== null) {
            VersioningScope::record(
                $this->versioning,
                $session,
                'maniforge_users',
                (string) $userId,
                'update',
                $before,
                $user,
                (string) ($user['login'] ?? $userId)
            );
        }
        JsonResponse::send(['ok' => true, 'user' => PublicUserPayload::fromUser($user)]);
    }

    public function changePassword(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        if (!$this->sessions->isStepUpFresh($session)) {
            JsonResponse::send(['ok' => false, 'error' => 'Требуется step-up re-auth'], 403);
            return;
        }

        $currentPassword = (string) ($ctx->input['current_password'] ?? '');
        $newPassword = (string) ($ctx->input['new_password'] ?? '');
        $result = $this->userSecurity->changePassword($session, $currentPassword, $newPassword);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function myContexts(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $result = $this->contexts->contextsForSession($session);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function switchContext(RequestContext $ctx): void
    {
        $token = $ctx->bearerToken();
        $session = $this->sessions->authenticate($token);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $result = $this->contexts->switchContext(
            $session,
            $token,
            (string) ($ctx->input['tenant_id'] ?? ''),
            (string) ($ctx->input['subtenant_id'] ?? '')
        );
        JsonResponse::send($result, (int) $result['status']);
    }

    public function switchProject(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $raw = $ctx->input['project_id'] ?? null;
        $projectId = null;
        if ($raw !== null && $raw !== '' && $raw !== 'null') {
            $projectId = (int) $raw;
            if ($projectId <= 0) {
                JsonResponse::send(['ok' => false, 'error' => 'Некорректный project_id'], 422);
                return;
            }
        }

        $result = $this->sessions->switchProject($session, $projectId);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function myPermissions(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->rbac->permissionsForUser(
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function myAccess(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'access' => $this->rbac->effectiveAccess(
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function consoleAccess(RequestContext $ctx): void
    {
        $session = $this->sessions->authenticate($ctx->bearerToken());
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return;
        }

        $userId = (int) $session['user_id'];
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $roles = $this->rbac->rolesForUser($userId, $tenantId, $subtenantId);
        $permissions = $this->rbac->permissionsForUser($userId, $tenantId, $subtenantId);

        $hasAdminPermission = false;
        foreach ($permissions as $permission) {
            if (str_starts_with((string) $permission, 'admin.')) {
                $hasAdminPermission = true;
                break;
            }
        }

        $isSuperAdmin = $this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, ['super_admin']);
        $licensingToken = trim((string) ($_ENV['TENANT_LICENSING_ADMIN_TOKEN'] ?? ''));

        $response = [
            'ok' => true,
            'roles' => $roles,
            'modules' => [
                'tenant' => $hasAdminPermission
                    || $this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, [
                        'super_admin',
                        'tenant_admin',
                        'subtenant_admin',
                        'security_auditor',
                    ]),
                'platform' => $isSuperAdmin,
            ],
        ];

        if ($isSuperAdmin) {
            $response['platform_licensing_token_configured'] = $licensingToken !== '';
            if ($licensingToken !== '' && $this->mayExposePlatformLicensingToken()) {
                $response['platform_licensing_token'] = $licensingToken;
            }
        }

        JsonResponse::send($response);
    }

    private function mayExposePlatformLicensingToken(): bool
    {
        if (filter_var($_ENV['RBAC_EXPOSE_PLATFORM_LICENSING_TOKEN'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'production')));

        return in_array($env, ['local', 'testing', 'test'], true)
            && filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
