<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Controllers;

use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Repository\RefreshTokenRepository;
use App\Maniforge\Rbac\Repository\RoleRepository;
use App\Maniforge\Rbac\Repository\PolicyRuleRepository;
use App\Maniforge\Rbac\Security\PolicyEngine;
use App\Maniforge\Rbac\Security\PasswordService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Security\RequestAuthenticator;
use App\Maniforge\Rbac\Security\RoleAdminService;
use App\Maniforge\Rbac\Security\SessionService;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Security\TenantLicensingClient;
use App\Maniforge\Rbac\Security\UserAdminService;
use App\Maniforge\Rbac\Security\UserOrganizationService;
use App\Maniforge\Rbac\Support\PublicUserPayload;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;

final class AdminController
{
    public function __construct(
        private readonly SessionService $sessions = new SessionService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly SessionRepository $sessionsRepo = new SessionRepository(),
        private readonly RefreshTokenRepository $refreshTokens = new RefreshTokenRepository(),
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly RoleAdminService $roleAdmin = new RoleAdminService(),
        private readonly UserAdminService $userAdmin = new UserAdminService(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly TenantLicensingClient $tenantLicensing = new TenantLicensingClient(),
        private readonly RegistrationService $registration = new RegistrationService(),
        private readonly UserOrganizationService $organizations = new UserOrganizationService(),
        private readonly PolicyEngine $policies = new PolicyEngine(),
        private readonly PolicyRuleRepository $policiesRepo = new PolicyRuleRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly RequestAuthenticator $authenticator = new RequestAuthenticator(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
    ) {
    }

    public function users(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->users->listUsers(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
        $this->audit->write(
            'admin.users.list',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            []
        );
    }

    public function createUser(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.status.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $login = $this->normalizeLogin((string) ($ctx->input['login'] ?? ''));
        $email = trim((string) ($ctx->input['email'] ?? ''));
        $phone = trim((string) ($ctx->input['phone'] ?? ''));
        $password = (string) ($ctx->input['password'] ?? '');
        $status = trim((string) ($ctx->input['status'] ?? 'active'));
        $mfaRequired = filter_var($ctx->input['mfa_required'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $reason = trim((string) ($ctx->input['reason'] ?? ''));

        if ($login === '' || $phone === '' || $password === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'login, phone, password и reason обязательны'], 422);
            return;
        }
        $normalizedEmail = $email === '' ? null : $email;
        if ($normalizedEmail !== null && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL) === false) {
            JsonResponse::send(['ok' => false, 'error' => 'Некорректный email'], 422);
            return;
        }
        if (!$this->userAdmin->isAllowedStatus($status)) {
            JsonResponse::send(['ok' => false, 'error' => 'Некорректный status'], 422);
            return;
        }
        if ($status === 'active' && !$this->guardUserActivationQuota($tenantId, $subtenantId)) {
            return;
        }

        try {
            $user = $this->users->createUser(
                $tenantId,
                $subtenantId,
                $login,
                $normalizedEmail,
                $phone,
                $this->passwords->hash($password),
                $mfaRequired,
                $status
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                JsonResponse::send(['ok' => false, 'error' => 'Пользователь с таким login/email уже существует в scope'], 409);
                return;
            }
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка создания пользователя'], 500);
            return;
        }

        $this->audit->write(
            'admin.users.create',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['target_user_id' => (int) ($user['id'] ?? 0), 'login' => $login, 'status' => $status, 'reason' => $reason]
        );
        $this->recordChange($session, 'maniforge_users', (string) ($user['id'] ?? 0), 'insert', null, $user, $login);
        JsonResponse::send(['ok' => true, 'user' => $user], 201);
    }

    public function attachOrganizationMember(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.assign');
        if ($session === null) {
            return;
        }

        $phone = trim((string) ($ctx->input['phone'] ?? ''));
        $roleCode = trim((string) ($ctx->input['role_code'] ?? 'user'));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));

        $result = $this->organizations->attachByPhone($session, $phone, $roleCode, $reason);
        if (($result['ok'] ?? false) === true && isset($result['user']) && is_array($result['user'])) {
            $result['user'] = PublicUserPayload::fromUser($result['user']);
        }
        JsonResponse::send($result, (int) $result['status']);
    }

    public function createRegistrationInvite(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.status.bulk');
        if ($session === null) {
            return;
        }

        $inviteType = strtolower(trim((string) ($ctx->input['invite_type'] ?? $ctx->input['type'] ?? 'subtenant')));
        $roleCode = trim((string) ($ctx->input['role_code'] ?? ''));
        $role = $roleCode !== '' ? $roleCode : null;

        if ($inviteType === 'user') {
            $result = $this->registration->createUserInvite(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                (int) $session['user_id'],
                $role
            );
        } else {
            $subtenantName = trim((string) ($ctx->input['subtenant_name'] ?? ''));
            $result = $this->registration->createSubtenantInvite(
                (string) $session['tenant_id'],
                $subtenantName,
                (int) $session['user_id'],
                $role
            );
        }

        if (($result['ok'] ?? false) === true && isset($result['invite']['id'])) {
            $invite = $result['invite'];
            $this->recordChange(
                $session,
                'maniforge_registration_invites',
                (string) $invite['id'],
                'insert',
                null,
                $invite,
                (string) ($invite['token_prefix'] ?? $invite['id'])
            );
        }

        JsonResponse::send($result, (int) $result['status']);
    }

    public function updateUser(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.status.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $targetUserId = (int) ($ctx->input['user_id'] ?? 0);
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        $current = $this->users->findByIdInScope($targetUserId, $tenantId, $subtenantId);
        if ($targetUserId <= 0 || $reason === '' || $current === null) {
            JsonResponse::send(['ok' => false, 'error' => 'user_id/reason обязательны, пользователь должен быть в scope'], 422);
            return;
        }

        $changes = [];
        if (array_key_exists('login', $ctx->input)) {
            $login = $this->normalizeLogin((string) $ctx->input['login']);
            if ($login === '') {
                JsonResponse::send(['ok' => false, 'error' => 'login не может быть пустым'], 422);
                return;
            }
            $changes['login'] = $login;
        }
        if (array_key_exists('email', $ctx->input)) {
            $email = trim((string) $ctx->input['email']);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                JsonResponse::send(['ok' => false, 'error' => 'Некорректный email'], 422);
                return;
            }
            $changes['email'] = $email;
        }
        if (array_key_exists('mfa_required', $ctx->input)) {
            $changes['mfa_required'] = filter_var($ctx->input['mfa_required'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('password', $ctx->input) && (string) $ctx->input['password'] !== '') {
            $changes['password_hash'] = $this->passwords->hash((string) $ctx->input['password']);
        }
        if (array_key_exists('status', $ctx->input)) {
            $status = trim((string) $ctx->input['status']);
            if (!$this->userAdmin->isAllowedStatus($status)) {
                JsonResponse::send(['ok' => false, 'error' => 'Некорректный status'], 422);
                return;
            }
            if ($status === 'active' && (string) ($current['status'] ?? '') !== 'active') {
                if (!$this->guardUserActivationQuota($tenantId, $subtenantId)) {
                    return;
                }
            }
            $changes['status'] = $status;
        }

        try {
            $user = $this->users->updateUserInScope($targetUserId, $tenantId, $subtenantId, $changes);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                JsonResponse::send(['ok' => false, 'error' => 'login/email уже занят в scope'], 409);
                return;
            }
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка обновления пользователя'], 500);
            return;
        }

        $revokedSessions = 0;
        if (in_array((string) ($changes['status'] ?? ''), ['locked', 'disabled'], true)) {
            $revokedSessions = $this->sessions->revokeAllForUser($targetUserId, 'user_status_changed:' . $changes['status']);
        }

        $this->audit->write(
            'admin.users.update',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            [
                'target_user_id' => $targetUserId,
                'changed_fields' => array_keys($changes),
                'revoked_sessions' => $revokedSessions,
                'reason' => $reason,
            ]
        );

        if ($user !== null && $changes !== []) {
            $this->recordChange(
                $session,
                'maniforge_users',
                (string) $targetUserId,
                'update',
                $current,
                $user,
                (string) ($user['login'] ?? $current['login'] ?? $targetUserId)
            );
        }

        JsonResponse::send(['ok' => true, 'user' => $user, 'revoked_sessions' => $revokedSessions]);
    }

    public function deleteUser(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.status.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $targetUserId = (int) ($ctx->input['user_id'] ?? 0);
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($targetUserId <= 0 || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'user_id и reason обязательны'], 422);
            return;
        }
        if ($targetUserId === (int) $session['user_id']) {
            JsonResponse::send(['ok' => false, 'error' => 'Нельзя удалить текущего администратора'], 403);
            return;
        }
        if (!$this->targetUserExistsInScope($targetUserId, $session)) {
            JsonResponse::send(['ok' => false, 'error' => 'Пользователь не найден в текущем контуре'], 404);
            return;
        }

        $beforeUser = $this->users->findByIdInScope($targetUserId, $tenantId, $subtenantId);
        $revokedSessions = $this->sessions->revokeAllForUser($targetUserId, 'user_deleted');
        $deleted = $this->users->deleteUserInScope($targetUserId, $tenantId, $subtenantId);
        $this->audit->write(
            'admin.users.delete',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['target_user_id' => $targetUserId, 'revoked_sessions' => $revokedSessions, 'reason' => $reason]
        );

        if ($deleted && $beforeUser !== null) {
            $this->recordChange(
                $session,
                'maniforge_users',
                (string) $targetUserId,
                'delete',
                $beforeUser,
                null,
                (string) ($beforeUser['login'] ?? $targetUserId)
            );
        }

        JsonResponse::send(['ok' => true, 'deleted' => $deleted, 'revoked_sessions' => $revokedSessions]);
    }

    public function sessions(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.sessions.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->sessionsRepo->listByScope(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
        $this->audit->write(
            'admin.sessions.list',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            []
        );
    }

    public function revokeSession(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.sessions.revoke');
        if ($session === null) {
            return;
        }

        $targetSessionId = (string) ($ctx->input['session_id'] ?? '');
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($targetSessionId === '') {
            JsonResponse::send(['ok' => false, 'error' => 'session_id обязателен'], 422);
            return;
        }
        if ($reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'reason обязателен'], 422);
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        if (!$this->sessionsRepo->existsInScope($targetSessionId, $tenantId, $subtenantId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Сессия не найдена в текущем контуре'], 404);
            return;
        }

        $revokeReason = 'admin_revoke:' . $reason;
        $sessionRevoked = $this->sessionsRepo->revokeInScope($targetSessionId, $tenantId, $subtenantId, $revokeReason);
        $refreshRevoked = $this->refreshTokens->revokeBySessionId($targetSessionId, $revokeReason);
        if ($sessionRevoked) {
            $this->recordChange(
                $session,
                'maniforge_sessions',
                $targetSessionId,
                'update',
                ['session_id' => $targetSessionId, 'revoked_at' => null],
                ['session_id' => $targetSessionId, 'revoked_at' => gmdate('Y-m-d H:i:s'), 'reason' => $revokeReason],
                $targetSessionId
            );
        }
        $this->audit->write(
            'admin.sessions.revoke',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            [
                'target_session_id' => $targetSessionId,
                'reason' => $reason,
                'session_revoked' => $sessionRevoked,
                'refresh_tokens_revoked' => $refreshRevoked,
            ]
        );
        $this->securityEvents->write(
            'admin.session.revoked',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            'warning',
            [
                'target_session_id' => $targetSessionId,
                'reason' => $reason,
                'session_revoked' => $sessionRevoked,
                'refresh_tokens_revoked' => $refreshRevoked,
            ]
        );
        JsonResponse::send([
            'ok' => true,
            'revoked_session_id' => $targetSessionId,
            'session_revoked' => $sessionRevoked,
            'refresh_tokens_revoked' => $refreshRevoked,
        ]);
    }

    public function batchRevokeSessions(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.sessions.bulk');
        if ($session === null) {
            return;
        }

        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        $sessionIds = $ctx->input['session_ids'] ?? null;
        $dryRun = filter_var($ctx->input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($reason === '' || !is_array($sessionIds) || $sessionIds === []) {
            JsonResponse::send(['ok' => false, 'error' => 'reason и непустой session_ids[] обязательны'], 422);
            return;
        }

        $maxItems = (int) ($_ENV['RBAC_BATCH_MAX_ITEMS'] ?? 100);
        if (count($sessionIds) > $maxItems) {
            JsonResponse::send(['ok' => false, 'error' => "Слишком большой batch, максимум {$maxItems}"], 422);
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $summary = ['revoked' => 0, 'skipped' => 0, 'total' => count($sessionIds)];
        $toRevoke = [];

        foreach ($sessionIds as $id) {
            $sessionId = (string) $id;
            if ($this->sessionsRepo->existsActiveInScope($sessionId, $tenantId, $subtenantId)) {
                $toRevoke[] = $sessionId;
                $summary['revoked']++;
            } else {
                $summary['skipped']++;
            }
        }

        if ($dryRun) {
            $this->audit->write(
                'admin.sessions.batch_revoke.dry_run',
                (int) $session['user_id'],
                $tenantId,
                $subtenantId,
                ['reason' => $reason, 'summary' => $summary]
            );
            JsonResponse::send(['ok' => true, 'dry_run' => true, 'summary' => $summary]);
            return;
        }

        try {
            $sessionIds = array_map(static fn ($id): string => (string) $id, $sessionIds);
            $actualRevoked = $this->sessionsRepo->revokeBatchInScope(
                $sessionIds,
                $tenantId,
                $subtenantId,
                'admin_batch_revoke:' . $reason
            );
            $refreshRevoked = $this->refreshTokens->revokeBySessionIdsInScope(
                $sessionIds,
                $tenantId,
                $subtenantId,
                'admin_batch_revoke:' . $reason
            );
        } catch (\Throwable $e) {
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка batch revoke sessions'], 500);
            return;
        }

        $summary['revoked'] = $actualRevoked;
        $summary['skipped'] = $summary['total'] - $actualRevoked;
        $summary['refresh_tokens_revoked'] = $refreshRevoked;

        $revokeReason = 'admin_batch_revoke:' . $reason;
        foreach ($toRevoke as $sessionId) {
            $this->recordChange(
                $session,
                'maniforge_sessions',
                $sessionId,
                'update',
                ['session_id' => $sessionId, 'revoked_at' => null],
                ['session_id' => $sessionId, 'revoked_at' => gmdate('Y-m-d H:i:s'), 'reason' => $revokeReason],
                $sessionId
            );
        }

        $this->audit->write(
            'admin.sessions.batch_revoke',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['reason' => $reason, 'summary' => $summary]
        );
        $this->securityEvents->write(
            'admin.sessions.batch_revoked',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            'warning',
            ['reason' => $reason, 'summary' => $summary]
        );

        JsonResponse::send(['ok' => true, 'summary' => $summary]);
    }

    public function opsSummary(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.read');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $users = $this->users->listUsers($tenantId, $subtenantId, 500);
        $activeUsers = 0;
        foreach ($users as $user) {
            if (is_array($user) && ($user['status'] ?? '') === 'active') {
                $activeUsers++;
            }
        }

        $auditItems = $this->audit->listByScope($tenantId, $subtenantId, 50);
        $securityItems = $this->securityEvents->listByScope($tenantId, $subtenantId, 50);

        JsonResponse::send([
            'ok' => true,
            'summary' => [
                'tenant_id' => $tenantId,
                'subtenant_id' => $subtenantId,
                'users_total' => count($users),
                'users_active' => $activeUsers,
                'sessions_active' => $this->sessionsRepo->countActiveInScope($tenantId, $subtenantId),
                'audit_recent' => count($auditItems),
                'security_events_recent' => count($securityItems),
                'step_up_required' => $this->policies->requiresStepUp($tenantId, $subtenantId),
                'action_token_configured' => trim((string) ($_ENV['RBAC_ACTION_TOKEN_TTL_SEC'] ?? '900')) !== '',
                'checked_at' => gmdate('Y-m-d H:i:s'),
            ],
        ]);
    }

    public function audit(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.audit.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->audit->listByScope(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function auditExport(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.audit.export');
        if ($session === null) {
            return;
        }

        $limit = min(20000, max(1, (int) ($ctx->input['limit'] ?? 5000)));
        $export = $this->audit->exportForScope(
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $limit
        );

        $this->audit->write(
            'admin.audit.exported',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['count' => $export['count'], 'manifest_sha256' => $export['manifest_sha256']]
        );

        JsonResponse::send(['ok' => true, 'export' => $export]);
    }

    public function securityEvents(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.security_events.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->securityEvents->listByScope(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function policyRules(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.policies.read');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $rules = $this->policies->getEffectiveAdminRules($tenantId, $subtenantId);

        JsonResponse::send([
            'ok' => true,
            'rules' => $rules,
        ]);
        $this->audit->write(
            'admin.policies.read',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['source' => (string) ($rules['source'] ?? 'unknown')]
        );
    }

    public function updatePolicyRules(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.policies.update');
        if ($session === null) {
            return;
        }

        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        $allowedIps = $ctx->input['allowed_ips'] ?? [];
        $hourStart = (int) ($ctx->input['allowed_hour_start_utc'] ?? -1);
        $hourEnd = (int) ($ctx->input['allowed_hour_end_utc'] ?? -1);
        $requireStepUp = filter_var($ctx->input['require_step_up'] ?? true, FILTER_VALIDATE_BOOLEAN);

        if ($reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'reason обязателен'], 422);
            return;
        }
        if (!is_array($allowedIps)) {
            JsonResponse::send(['ok' => false, 'error' => 'allowed_ips должен быть массивом'], 422);
            return;
        }
        if ($hourStart < 0 || $hourStart > 23 || $hourEnd < 0 || $hourEnd > 23 || $hourStart > $hourEnd) {
            JsonResponse::send(['ok' => false, 'error' => 'Некорректное окно allowed_hour_start_utc/allowed_hour_end_utc'], 422);
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $beforeRules = $this->policies->getEffectiveAdminRules($tenantId, $subtenantId);
        if (
            !$requireStepUp
            && !$this->rbac->hasAnyRole((int) $session['user_id'], $tenantId, $subtenantId, ['super_admin'])
        ) {
            JsonResponse::send(['ok' => false, 'error' => 'Отключать step-up может только super_admin'], 403);
            return;
        }

        $normalizedIps = [];
        foreach ($allowedIps as $ip) {
            $value = trim((string) $ip);
            if ($value === '') {
                continue;
            }
            if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                JsonResponse::send(['ok' => false, 'error' => "Некорректный IP: {$value}"], 422);
                return;
            }
            $normalizedIps[] = $value;
        }

        $this->policiesRepo->upsertForScope(
            $tenantId,
            $subtenantId,
            array_values(array_unique($normalizedIps)),
            $hourStart,
            $hourEnd,
            $requireStepUp,
            (int) $session['user_id']
        );

        $effective = $this->policies->getEffectiveAdminRules($tenantId, $subtenantId);
        $this->recordChange(
            $session,
            'maniforge_policy_rules',
            $tenantId . ':' . $subtenantId,
            'update',
            $beforeRules,
            $effective,
            'admin-policy'
        );
        $this->audit->write(
            'admin.policies.update',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            [
                'reason' => $reason,
                'rules' => [
                    'allowed_ips' => $effective['allowed_ips'] ?? [],
                    'allowed_hour_start_utc' => $effective['allowed_hour_start_utc'] ?? null,
                    'allowed_hour_end_utc' => $effective['allowed_hour_end_utc'] ?? null,
                    'require_step_up' => $effective['require_step_up'] ?? null,
                ],
            ]
        );
        $this->securityEvents->write(
            'admin.policies.updated',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            'warning',
            [
                'reason' => $reason,
                'source' => $effective['source'] ?? 'unknown',
            ]
        );

        JsonResponse::send(['ok' => true, 'rules' => $effective]);
    }

    public function roles(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.roles.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'scope_prefix' => $this->roleScopePrefix((string) $session['tenant_id'], (string) $session['subtenant_id']),
            'items' => $this->roles->listRoles(
                $this->roleScopePrefix((string) $session['tenant_id'], (string) $session['subtenant_id'])
            ),
        ]);
    }

    public function createRole(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $roleCode = $this->scopedRoleCode($tenantId, $subtenantId, (string) ($ctx->input['code'] ?? ''));
        $name = trim((string) ($ctx->input['name'] ?? ''));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($roleCode === '' || $name === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'code, name и reason обязательны'], 422);
            return;
        }

        try {
            $role = $this->roles->createRole($roleCode, $name);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                JsonResponse::send(['ok' => false, 'error' => 'Роль уже существует'], 409);
                return;
            }
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка создания роли'], 500);
            return;
        }

        $this->audit->write(
            'admin.roles.create',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['role_code' => $roleCode, 'reason' => $reason]
        );
        $this->recordChange($session, 'maniforge_roles', $roleCode, 'insert', null, $role, $roleCode);
        JsonResponse::send(['ok' => true, 'role' => $role], 201);
    }

    public function updateRole(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $roleCode = $this->scopedRoleCode($tenantId, $subtenantId, (string) ($ctx->input['code'] ?? ''));
        $name = trim((string) ($ctx->input['name'] ?? ''));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($roleCode === '' || $name === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'code, name и reason обязательны'], 422);
            return;
        }
        if (!$this->isMutableRoleInScope($roleCode, $tenantId, $subtenantId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Можно менять только custom-роли текущего scope'], 403);
            return;
        }

        $beforeRole = $this->roles->findRoleByCode($roleCode);
        $role = $this->roles->updateRole($roleCode, $name);
        $this->audit->write(
            'admin.roles.update',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['role_code' => $roleCode, 'reason' => $reason]
        );
        if ($role !== null) {
            $this->recordChange($session, 'maniforge_roles', $roleCode, 'update', $beforeRole, $role, $roleCode);
        }
        JsonResponse::send(['ok' => true, 'role' => $role]);
    }

    public function deleteRole(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $roleCode = $this->scopedRoleCode($tenantId, $subtenantId, (string) ($ctx->input['code'] ?? ''));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($roleCode === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'code и reason обязательны'], 422);
            return;
        }
        if (!$this->isMutableRoleInScope($roleCode, $tenantId, $subtenantId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Можно удалить только custom-роли текущего scope'], 403);
            return;
        }

        $beforeRole = $this->roles->findRoleByCode($roleCode);
        $deleted = $this->roles->deleteRole($roleCode);
        $this->audit->write(
            'admin.roles.delete',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['role_code' => $roleCode, 'reason' => $reason]
        );
        if ($deleted && $beforeRole !== null) {
            $this->recordChange($session, 'maniforge_roles', $roleCode, 'delete', $beforeRole, null, $roleCode);
        }
        JsonResponse::send(['ok' => true, 'deleted' => $deleted]);
    }

    public function permissions(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.permissions.read');
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->roles->listPermissions(),
        ]);
    }

    public function rolePermissions(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.permissions.read');
        if ($session === null) {
            return;
        }

        $roleCode = $this->scopedRoleCode(
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            (string) ($ctx->input['role_code'] ?? $_GET['role_code'] ?? '')
        );
        if ($roleCode === '') {
            JsonResponse::send(['ok' => false, 'error' => 'role_code обязателен'], 422);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'role_code' => $roleCode,
            'items' => $this->roles->listRolePermissions($roleCode),
        ]);
    }

    public function replaceRolePermissions(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.bulk');
        if ($session === null) {
            return;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $roleCode = $this->scopedRoleCode($tenantId, $subtenantId, (string) ($ctx->input['role_code'] ?? ''));
        $permissionCodes = $ctx->input['permissions'] ?? null;
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($roleCode === '' || !is_array($permissionCodes) || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'role_code, permissions[] и reason обязательны'], 422);
            return;
        }
        if (!$this->isMutableRoleInScope($roleCode, $tenantId, $subtenantId)) {
            JsonResponse::send(['ok' => false, 'error' => 'Permissions можно менять только у custom-роли текущего scope'], 403);
            return;
        }

        $beforePermissions = $this->roles->listRolePermissions($roleCode);
        $result = $this->roles->replaceRolePermissions($roleCode, $permissionCodes);
        if (($result['ok'] ?? false) !== true) {
            JsonResponse::send(['ok' => false, 'error' => $result['error'] ?? 'Ошибка permissions', 'missing' => $result['missing'] ?? []], 422);
            return;
        }

        $this->audit->write(
            'admin.roles.permissions.replace',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['role_code' => $roleCode, 'permissions' => $permissionCodes, 'reason' => $reason]
        );
        $this->recordChange(
            $session,
            'maniforge_role_permissions',
            $roleCode,
            'update',
            ['permissions' => $beforePermissions],
            ['permissions' => $result['permissions'] ?? []],
            $roleCode
        );
        JsonResponse::send(['ok' => true, 'permissions' => $result['permissions']]);
    }

    public function userRoles(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.read');
        if ($session === null) {
            return;
        }

        $targetUserId = (int) ($ctx->input['user_id'] ?? $_GET['user_id'] ?? $ctx->server['HTTP_X_USER_ID'] ?? 0);
        if ($targetUserId <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'user_id обязателен'], 422);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->roles->listUserRoles(
                $targetUserId,
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    public function assignUserRole(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.assign');
        if ($session === null) {
            return;
        }

        $targetUserId = (int) ($ctx->input['user_id'] ?? 0);
        $roleCode = trim((string) ($ctx->input['role_code'] ?? ''));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($targetUserId <= 0 || $roleCode === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'user_id, role_code и reason обязательны'], 422);
            return;
        }
        if (!$this->targetUserExistsInScope($targetUserId, $session)) {
            JsonResponse::send(['ok' => false, 'error' => 'Пользователь не найден в текущем контуре'], 404);
            return;
        }

        $guard = $this->roleAdmin->guardRoleMutation(
            (int) $session['user_id'],
            $targetUserId,
            $roleCode,
            'assign',
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if (!$guard['ok']) {
            JsonResponse::send(['ok' => false, 'error' => $guard['error']], 403);
            return;
        }

        $ok = $this->roles->assignRoleByCode(
            $targetUserId,
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $roleCode,
            (int) $session['user_id']
        );
        if (!$ok) {
            JsonResponse::send(['ok' => false, 'error' => 'Роль не найдена'], 404);
            return;
        }

        $this->audit->write(
            'admin.user_roles.assign',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['target_user_id' => $targetUserId, 'role_code' => $roleCode, 'reason' => $reason]
        );
        $this->recordChange(
            $session,
            'maniforge_user_roles',
            $targetUserId . ':' . $roleCode,
            'insert',
            null,
            ['user_id' => $targetUserId, 'role_code' => $roleCode],
            $roleCode
        );
        $this->securityEvents->write(
            'admin.user_role.assigned',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            'warning',
            ['target_user_id' => $targetUserId, 'role_code' => $roleCode, 'reason' => $reason]
        );

        JsonResponse::send(['ok' => true, 'assigned' => true]);
    }

    public function revokeUserRole(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.revoke');
        if ($session === null) {
            return;
        }

        $targetUserId = (int) ($ctx->input['user_id'] ?? 0);
        $roleCode = trim((string) ($ctx->input['role_code'] ?? ''));
        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        if ($targetUserId <= 0 || $roleCode === '' || $reason === '') {
            JsonResponse::send(['ok' => false, 'error' => 'user_id, role_code и reason обязательны'], 422);
            return;
        }
        if (!$this->targetUserExistsInScope($targetUserId, $session)) {
            JsonResponse::send(['ok' => false, 'error' => 'Пользователь не найден в текущем контуре'], 404);
            return;
        }

        $guard = $this->roleAdmin->guardRoleMutation(
            (int) $session['user_id'],
            $targetUserId,
            $roleCode,
            'revoke',
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if (!$guard['ok']) {
            JsonResponse::send(['ok' => false, 'error' => $guard['error']], 403);
            return;
        }

        $ok = $this->roles->revokeRoleByCode(
            $targetUserId,
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $roleCode
        );
        if (!$ok) {
            JsonResponse::send(['ok' => false, 'error' => 'Роль не найдена или не назначена'], 404);
            return;
        }

        $this->audit->write(
            'admin.user_roles.revoke',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['target_user_id' => $targetUserId, 'role_code' => $roleCode, 'reason' => $reason]
        );
        $this->recordChange(
            $session,
            'maniforge_user_roles',
            $targetUserId . ':' . $roleCode,
            'delete',
            ['user_id' => $targetUserId, 'role_code' => $roleCode],
            null,
            $roleCode
        );
        $this->securityEvents->write(
            'admin.user_role.revoked',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            'warning',
            ['target_user_id' => $targetUserId, 'role_code' => $roleCode, 'reason' => $reason]
        );

        JsonResponse::send(['ok' => true, 'revoked' => true]);
    }

    public function batchUserRoles(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_roles.bulk');
        if ($session === null) {
            return;
        }

        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        $items = $ctx->input['items'] ?? null;
        $dryRun = filter_var($ctx->input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($reason === '' || !is_array($items) || $items === []) {
            JsonResponse::send(['ok' => false, 'error' => 'reason и непустой items[] обязательны'], 422);
            return;
        }

        $maxItems = (int) ($_ENV['RBAC_BATCH_MAX_ITEMS'] ?? 100);
        if (count($items) > $maxItems) {
            JsonResponse::send(['ok' => false, 'error' => "Слишком большой batch, максимум {$maxItems}"], 422);
            return;
        }

        foreach ($items as $index => $item) {
            $targetUserId = (int) ($item['user_id'] ?? 0);
            $roleCode = trim((string) ($item['role_code'] ?? ''));
            $action = trim((string) ($item['action'] ?? ''));
            if ($targetUserId <= 0 || $roleCode === '' || !in_array($action, ['assign', 'revoke'], true)) {
                JsonResponse::send([
                    'ok' => false,
                    'error' => 'Неверный элемент batch',
                    'item_index' => $index,
                ], 422);
                return;
            }
            if (!$this->targetUserExistsInScope($targetUserId, $session)) {
                JsonResponse::send([
                    'ok' => false,
                    'error' => 'Пользователь не найден в текущем контуре',
                    'item_index' => $index,
                ], 404);
                return;
            }

            $guard = $this->roleAdmin->guardRoleMutation(
                (int) $session['user_id'],
                $targetUserId,
                $roleCode,
                $action,
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            );
            if (!$guard['ok']) {
                JsonResponse::send([
                    'ok' => false,
                    'error' => $guard['error'],
                    'item_index' => $index,
                ], 403);
                return;
            }
        }

        if ($dryRun) {
            $summary = $this->roleAdmin->simulateBatchSummary(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                $items
            );

            $this->audit->write(
                'admin.user_roles.batch.dry_run',
                (int) $session['user_id'],
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                ['reason' => $reason, 'summary' => $summary]
            );

            JsonResponse::send(['ok' => true, 'dry_run' => true, 'summary' => $summary]);
            return;
        }

        try {
            $summary = $this->roles->applyRoleMutationsBatch(
                (string) $session['tenant_id'],
                (string) $session['subtenant_id'],
                (int) $session['user_id'],
                $items
            );
        } catch (\Throwable $e) {
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка batch role update'], 500);
            return;
        }

        foreach ($items as $item) {
            $targetUserId = (int) ($item['user_id'] ?? 0);
            $roleCode = trim((string) ($item['role_code'] ?? ''));
            $action = trim((string) ($item['action'] ?? ''));
            if ($targetUserId <= 0 || $roleCode === '') {
                continue;
            }
            $this->recordChange(
                $session,
                'maniforge_user_roles',
                $targetUserId . ':' . $roleCode,
                $action === 'revoke' ? 'delete' : 'insert',
                $action === 'revoke' ? ['user_id' => $targetUserId, 'role_code' => $roleCode] : null,
                $action === 'assign' ? ['user_id' => $targetUserId, 'role_code' => $roleCode] : null,
                $roleCode
            );
        }

        $this->audit->write(
            'admin.user_roles.batch',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['reason' => $reason, 'summary' => $summary]
        );
        $this->securityEvents->write(
            'admin.user_roles.batch',
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            'warning',
            ['reason' => $reason, 'summary' => $summary]
        );

        JsonResponse::send(['ok' => true, 'summary' => $summary]);
    }

    public function batchUserStatus(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.users.status.bulk');
        if ($session === null) {
            return;
        }

        $reason = trim((string) ($ctx->input['reason'] ?? ''));
        $items = $ctx->input['items'] ?? null;
        $dryRun = filter_var($ctx->input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($reason === '' || !is_array($items) || $items === []) {
            JsonResponse::send(['ok' => false, 'error' => 'reason и непустой items[] обязательны'], 422);
            return;
        }

        $maxItems = (int) ($_ENV['RBAC_BATCH_MAX_ITEMS'] ?? 100);
        if (count($items) > $maxItems) {
            JsonResponse::send(['ok' => false, 'error' => "Слишком большой batch, максимум {$maxItems}"], 422);
            return;
        }

        foreach ($items as $index => $item) {
            $targetUserId = (int) ($item['user_id'] ?? 0);
            $status = trim((string) ($item['status'] ?? ''));
            if ($targetUserId <= 0 || !$this->userAdmin->isAllowedStatus($status)) {
                JsonResponse::send([
                    'ok' => false,
                    'error' => 'Неверный элемент batch',
                    'item_index' => $index,
                ], 422);
                return;
            }
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];

        $beforeUsers = [];
        foreach ($items as $item) {
            $uid = (int) ($item['user_id'] ?? 0);
            if ($uid > 0) {
                $beforeUsers[$uid] = $this->users->findByIdInScope($uid, $tenantId, $subtenantId);
            }
        }

        if ($dryRun) {
            $summary = $this->userAdmin->simulateStatusBatchSummary($tenantId, $subtenantId, $items);
            $this->audit->write(
                'admin.users.batch_status.dry_run',
                (int) $session['user_id'],
                $tenantId,
                $subtenantId,
                ['reason' => $reason, 'summary' => $summary]
            );
            JsonResponse::send(['ok' => true, 'dry_run' => true, 'summary' => $summary]);
            return;
        }

        try {
            $summary = $this->users->applyStatusBatchInScope($tenantId, $subtenantId, $items);
        } catch (\Throwable $e) {
            JsonResponse::send(['ok' => false, 'error' => 'Ошибка batch user status update'], 500);
            return;
        }

        $revokedSessions = 0;
        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if (!in_array($status, ['locked', 'disabled'], true)) {
                continue;
            }

            $targetUserId = (int) ($item['user_id'] ?? 0);
            if ($this->targetUserExistsInScope($targetUserId, $session)) {
                $revokedSessions += $this->sessions->revokeAllForUser(
                    $targetUserId,
                    'user_status_changed:' . $status
                );
            }
        }
        $summary['revoked_sessions'] = $revokedSessions;

        foreach ($items as $item) {
            $uid = (int) ($item['user_id'] ?? 0);
            if ($uid <= 0) {
                continue;
            }
            $after = $this->users->findByIdInScope($uid, $tenantId, $subtenantId);
            $before = $beforeUsers[$uid] ?? null;
            if ($before !== null && $after !== null && ($before['status'] ?? '') !== ($after['status'] ?? '')) {
                $this->recordChange(
                    $session,
                    'maniforge_users',
                    (string) $uid,
                    'update',
                    $before,
                    $after,
                    (string) ($after['login'] ?? $uid)
                );
            }
        }

        $this->audit->write(
            'admin.users.batch_status',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            ['reason' => $reason, 'summary' => $summary]
        );
        $this->securityEvents->write(
            'admin.users.batch_status.updated',
            (int) $session['user_id'],
            $tenantId,
            $subtenantId,
            'warning',
            ['reason' => $reason, 'summary' => $summary]
        );

        JsonResponse::send(['ok' => true, 'summary' => $summary]);
    }

    public function effectiveAccess(RequestContext $ctx): void
    {
        $session = $this->guardAdmin($ctx, 'admin.user_access.read');
        if ($session === null) {
            return;
        }

        $targetUserId = (int) ($ctx->input['user_id'] ?? $_GET['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            JsonResponse::send(['ok' => false, 'error' => 'user_id обязателен'], 422);
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'user_id' => $targetUserId,
            'access' => $this->rbac->effectiveAccess(
                $targetUserId,
                (string) $session['tenant_id'],
                (string) $session['subtenant_id']
            ),
        ]);
    }

    private function targetUserExistsInScope(int $targetUserId, array $session): bool
    {
        return $this->users->findStatusInScope(
            $targetUserId,
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        ) !== null;
    }

    private function normalizeLogin(string $login): string
    {
        return strtolower(trim($login));
    }

    private function guardUserActivationQuota(string $tenantId, string $subtenantId): bool
    {
        $activeUsers = $this->users->countActiveUsers($tenantId, $subtenantId);
        $access = $this->tenantLicensing->assertUserActivationAllowed($tenantId, $subtenantId, $activeUsers);
        if (($access['ok'] ?? false) === true) {
            return true;
        }

        JsonResponse::send([
            'ok' => false,
            'error' => $access['error'] ?? 'Tenant license/quota не позволяет активировать пользователя',
            'deny_reason' => $access['deny_reason'] ?? 'tenant_license_denied',
            'seats' => $access['seats'] ?? null,
        ], (int) ($access['status'] ?? 402));

        return false;
    }

    private function roleScopePrefix(string $tenantId, string $subtenantId): string
    {
        return $this->safeRoleSegment($tenantId) . '__' . $this->safeRoleSegment($subtenantId) . '__';
    }

    private function scopedRoleCode(string $tenantId, string $subtenantId, string $code): string
    {
        $code = $this->safeRoleSegment($code);
        if ($code === '') {
            return '';
        }

        $prefix = $this->roleScopePrefix($tenantId, $subtenantId);
        if (str_starts_with($code, $prefix)) {
            return $code;
        }

        return substr($prefix . $code, 0, 80);
    }

    private function isMutableRoleInScope(string $roleCode, string $tenantId, string $subtenantId): bool
    {
        $role = $this->roles->findRoleByCode($roleCode);
        if ($role === null || (int) ($role['is_system'] ?? 0) === 1) {
            return false;
        }

        return str_starts_with($roleCode, $this->roleScopePrefix($tenantId, $subtenantId));
    }

    private function safeRoleSegment(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_]+/', '_', $value) ?? '';

        return trim($value, '_');
    }

    private function guardAdmin(RequestContext $ctx, string $requiredPermission): ?array
    {
        $session = $this->authenticator->authenticateSession($ctx);
        if ($session === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Не авторизован'], 401);
            return null;
        }

        $allowed = $this->rbac->hasAnyRole(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['super_admin', 'tenant_admin', 'subtenant_admin', 'security_auditor']
        );
        if (!$allowed) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно прав'], 403);
            return null;
        }

        $hasPermission = $this->rbac->hasPermission(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $requiredPermission
        );
        if (!$hasPermission) {
            JsonResponse::send(['ok' => false, 'error' => 'Недостаточно permissions'], 403);
            return null;
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $policy = $this->policies->allowsAdminAction($ctx->server, $tenantId, $subtenantId);
        if (!$policy['ok']) {
            JsonResponse::send(['ok' => false, 'error' => $policy['error']], 403);
            return null;
        }

        $mutating = in_array($ctx->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
        if ($mutating && !$this->authenticator->satisfiesSensitiveAction($ctx, $session)) {
            JsonResponse::send($this->authenticator->stepUpRequiredError(), 403);
            return null;
        }

        return $session;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function recordChange(
        array $session,
        string $entityTable,
        string $entityId,
        string $operation,
        ?array $before,
        ?array $after,
        ?string $entityLabel = null,
        ?int $projectId = null
    ): void {
        VersioningScope::record(
            $this->versioning,
            $session,
            $entityTable,
            $entityId,
            $operation,
            $before,
            $after,
            $entityLabel,
            $projectId
        );
    }

}
