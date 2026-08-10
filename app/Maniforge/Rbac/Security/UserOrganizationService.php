<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Repository\RegistrationInviteRepository;
use App\Maniforge\Rbac\Repository\RoleRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Versioning\Security\ChangeRecorder;

/**
 * Привязка существующего человека (один телефон) к ещё одной организации без повторной регистрации.
 */
final class UserOrganizationService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly EntityMetaRepository $entityMeta = new EntityMetaRepository(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly TenantLicensingClient $tenantLicensing = new TenantLicensingClient(),
        private readonly PersonalDataService $personalData = new PersonalDataService(),
        private readonly PdBootstrapService $pdBootstrap = new PdBootstrapService(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly RegistrationInviteRepository $invites = new RegistrationInviteRepository(),
    ) {
    }

    /**
     * Админ добавляет пользователя по телефону в текущую организацию (как кабинет WB: тот же номер, новая компания).
     *
     * @param array<string, mixed> $session
     * @return array{ok: bool, status: int, user?: array, role_code?: string, error?: string, code?: string}
     */
    public function attachByPhone(array $session, string $phone, string $roleCode, string $reason): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $phone = $this->normalizePhone($phone);
        $roleCode = trim($roleCode) !== '' ? trim($roleCode) : 'user';
        $reason = trim($reason);

        if ($phone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'phone обязателен'];
        }
        if ($reason === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'reason обязателен'];
        }

        $sources = $this->activeUsersWithPhone($phone);
        if ($sources === []) {
            return [
                'ok' => false,
                'status' => 404,
                'code' => 'user_not_found',
                'error' => 'Пользователь с этим телефоном не найден. Сначала регистрация или invite.',
            ];
        }

        $this->pdBootstrap->seedTenant($tenantId, 'Organization ' . $tenantId);

        return $this->attachUsingSourceUser(
            $sources[0],
            $tenantId,
            $subtenantId,
            $roleCode,
            (int) $session['user_id'],
            ['reason' => $reason, 'flow' => 'admin_attach']
        );
    }

    /**
     * Уже авторизованный пользователь принимает invite в новую организацию.
     *
     * @param array<string, mixed> $session
     * @return array{ok: bool, status: int, user?: array, tenant?: array, error?: string, code?: string}
     */
    public function acceptInvite(array $session, string $inviteToken): array
    {
        $inviteToken = trim($inviteToken);
        if ($inviteToken === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'invite_token обязателен'];
        }

        if ($this->invites->isConsumedToken($inviteToken)) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'invite_already_used',
                'error' => 'Приглашение уже использовано',
            ];
        }

        $invite = $this->invites->findPendingByToken($inviteToken);
        if ($invite === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Приглашение недействительно или истекло'];
        }

        $tenantId = (string) $invite['tenant_id'];
        $subtenantCode = strtolower(trim((string) ($invite['subtenant_code'] ?? '')));
        if ($subtenantCode === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'Приглашение без subtenant'];
        }

        $roleCode = trim((string) ($invite['role_code'] ?? 'user'));
        $actorId = (int) $session['user_id'];
        $source = $this->users->findByIdInScope(
            $actorId,
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if ($source === null) {
            return ['ok' => false, 'status' => 401, 'error' => 'Сессия недействительна'];
        }

        $phone = trim((string) ($source['phone'] ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'У пользователя не задан телефон'];
        }

        if ($this->users->findByPhoneInScope($phone, $tenantId, $subtenantCode) !== null) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'already_member',
                'error' => 'Вы уже состоите в этой организации',
            ];
        }

        $claimed = $this->invites->claimPendingByToken($inviteToken, $subtenantCode);
        if ($claimed === null) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'invite_already_used',
                'error' => 'Приглашение уже использовано',
            ];
        }

        $this->pdBootstrap->seedTenant($tenantId, 'Organization ' . $tenantId);

        $result = $this->attachUsingSourceUser(
            $source,
            $tenantId,
            $subtenantCode,
            $roleCode,
            $actorId,
            ['flow' => 'accept_invite', 'invite_id' => (int) ($claimed['id'] ?? 0)]
        );
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $result['tenant'] = [
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantCode,
            'invite_id' => (int) ($claimed['id'] ?? 0),
        ];

        return $result;
    }

    /**
     * Проверки перед атомарным claim invite (без записи пользователя).
     *
     * @param array<string, mixed> $invite
     * @param list<array<string, mixed>> $consentItems
     * @return array{ok: bool, status: int, error?: string, code?: string, sources?: list<array>}
     */
    public function validateAttachViaInviteRegistration(
        array $invite,
        string $phone,
        string $password,
        array $consentItems
    ): array {
        $tenantId = (string) $invite['tenant_id'];
        $subtenantCode = strtolower(trim((string) ($invite['subtenant_code'] ?? '')));

        if ($subtenantCode === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'Приглашение пользователя некорректно'];
        }

        if ($this->users->findByPhoneInScope($phone, $tenantId, $subtenantCode) !== null) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'already_member',
                'error' => 'Вы уже состоите в этой организации',
            ];
        }

        $sources = $this->activeUsersWithPhone($phone);
        if ($sources === []) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        if (!$this->verifyPasswordForAnyUser($password, $sources)) {
            return ['ok' => false, 'status' => 401, 'error' => 'Неверный пароль', 'code' => 'invalid_password'];
        }

        $this->pdBootstrap->seedTenant($tenantId, 'Organization ' . $tenantId);
        $consentItems = $this->personalData->filterConsentsKnownInTenant($tenantId, $consentItems);
        $consentError = $this->personalData->validateRegistrationConsents($tenantId, $consentItems);
        if ($consentError !== null) {
            return $consentError;
        }

        return ['ok' => true, 'status' => 200, 'sources' => $sources];
    }

    /**
     * Привязка после claim invite (invite уже consumed).
     *
     * @param array<string, mixed> $invite
     * @param list<array<string, mixed>> $consentItems
     * @param list<array<string, mixed>> $sources
     * @return array{ok: bool, status: int, user?: array, role_code?: string, tenant?: array, error?: string, code?: string}
     */
    public function attachAfterInviteClaim(
        array $invite,
        string $phone,
        ?string $email,
        array $consentItems,
        array $server,
        array $sources
    ): array {
        $tenantId = (string) $invite['tenant_id'];
        $subtenantCode = strtolower(trim((string) ($invite['subtenant_code'] ?? '')));
        $roleCode = trim((string) ($invite['role_code'] ?? 'user'));
        $source = $sources[0] ?? null;
        if (!is_array($source)) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        $result = $this->attachUsingSourceUser(
            $source,
            $tenantId,
            $subtenantCode,
            $roleCode,
            (int) ($source['id'] ?? 0),
            ['flow' => 'invite_register_attach', 'invite_id' => (int) ($invite['id'] ?? 0)]
        );

        if (($result['ok'] ?? false) === true) {
            $userId = (int) (($result['user']['id'] ?? 0));
            if ($userId > 0 && $consentItems !== []) {
                $filtered = $this->personalData->filterConsentsKnownInTenant($tenantId, $consentItems);
                $this->personalData->recordRegistrationConsents($userId, $tenantId, $subtenantCode, $filtered, $server);
            }
            $result['tenant'] = [
                'tenant_id' => $tenantId,
                'subtenant_id' => $subtenantCode,
                'invite_id' => (int) ($invite['id'] ?? 0),
            ];
            $result['role_code'] = $roleCode;
        }

        return $result;
    }

    /** @deprecated Use validateAttachViaInviteRegistration + attachAfterInviteClaim */
    public function attachViaInviteRegistration(
        array $invite,
        string $phone,
        string $password,
        ?string $email,
        array $consentItems,
        array $server
    ): array {
        $validated = $this->validateAttachViaInviteRegistration($invite, $phone, $password, $consentItems);
        if (($validated['ok'] ?? false) !== true) {
            return $validated;
        }

        return $this->attachAfterInviteClaim(
            $invite,
            $phone,
            $email,
            $consentItems,
            $server,
            $validated['sources'] ?? []
        );
    }

    /**
     * @param array<string, mixed> $sourceUser
     * @param array<string, mixed> $meta
     * @return array{ok: bool, status: int, user?: array, role_code?: string, error?: string, code?: string}
     */
    private function attachUsingSourceUser(
        array $sourceUser,
        string $tenantId,
        string $subtenantId,
        string $roleCode,
        int $actorUserId,
        array $meta
    ): array {
        $phone = trim((string) ($sourceUser['phone'] ?? ''));
        if ($phone === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'У исходного пользователя нет телефона'];
        }

        if ($this->users->findByPhoneInScope($phone, $tenantId, $subtenantId) !== null) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'already_member',
                'error' => 'Пользователь уже в этой организации',
            ];
        }

        $access = $this->tenantLicensing->assertAccess($tenantId, $subtenantId);
        if (($access['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Организация недоступна'),
            ];
        }

        $activeUsers = $this->users->countActiveUsers($tenantId, $subtenantId);
        $quota = $this->tenantLicensing->assertUserActivationAllowed($tenantId, $subtenantId, $activeUsers);
        if (($quota['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($quota['status'] ?? 402),
                'error' => (string) ($quota['error'] ?? 'Лимит пользователей'),
            ];
        }

        $login = $this->allocateLoginInScope($phone, $tenantId, $subtenantId);
        $passwordHash = (string) ($sourceUser['password_hash'] ?? '');
        if ($passwordHash === '') {
            return ['ok' => false, 'status' => 500, 'error' => 'Не удалось скопировать учётные данные'];
        }

        $email = isset($sourceUser['email']) && $sourceUser['email'] !== ''
            ? (string) $sourceUser['email']
            : null;

        try {
            $user = $this->users->createUser(
                $tenantId,
                $subtenantId,
                $login,
                $email,
                $phone,
                $passwordHash,
                (bool) ($sourceUser['mfa_required'] ?? false),
                'active'
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Конфликт уникальности в scope'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка привязки к организации'];
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0) {
            $this->roles->assignRoleByCode($userId, $tenantId, $subtenantId, $roleCode, $actorUserId > 0 ? $actorUserId : $userId);
            $this->entityMeta->rebindPhoneForUser($phone, $userId, $tenantId, $subtenantId);
        }

        $this->audit->write('auth.organization.attached', $actorUserId > 0 ? $actorUserId : $userId, $tenantId, $subtenantId, array_merge($meta, [
            'target_user_id' => $userId,
            'phone' => $phone,
            'role_code' => $roleCode,
            'source_user_id' => (int) ($sourceUser['id'] ?? 0),
        ]));
        $this->securityEvents->write('auth.organization.attached', $userId, $tenantId, $subtenantId, 'info', [
            'role_code' => $roleCode,
        ]);
        $this->versioning->record(
            [
                'tenant_id' => $tenantId,
                'subtenant_id' => $subtenantId,
                'project_id' => null,
                'actor_user_id' => $actorUserId > 0 ? $actorUserId : $userId,
                'correlation_id' => null,
            ],
            'maniforge_users',
            (string) $userId,
            'insert',
            null,
            $user,
            $login
        );

        return [
            'ok' => true,
            'status' => 201,
            'user' => $user,
            'role_code' => $roleCode,
        ];
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function verifyPasswordForAnyUser(string $password, array $users): bool
    {
        foreach ($users as $user) {
            $hash = (string) ($user['password_hash'] ?? '');
            if ($hash !== '' && $this->passwords->verify($password, $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeUsersWithPhone(string $phone): array
    {
        return array_values(array_filter(
            $this->users->findAllByPhone($phone),
            static fn (array $u): bool => (string) ($u['status'] ?? 'active') === 'active'
        ));
    }

    private function allocateLoginInScope(string $phone, string $tenantId, string $subtenantId): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        $base = $digits !== '' ? 'u' . $digits : 'u' . bin2hex(random_bytes(4));
        if (strlen($base) > 64) {
            $base = substr($base, 0, 64);
        }
        $candidate = strtolower($base);
        $suffix = 2;
        while ($this->users->findByLogin($tenantId, $subtenantId, $candidate) !== null) {
            $tail = '_' . $suffix;
            $candidate = substr($base, 0, max(3, 64 - strlen($tail))) . $tail;
            $suffix++;
        }

        return $candidate;
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

        return $digits === '' ? '' : '+' . $digits;
    }
}
