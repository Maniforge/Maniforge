<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Repository\RegistrationInviteRepository;
use App\Maniforge\Rbac\Repository\RoleRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;
use App\Maniforge\Versioning\Security\ChangeRecorder;

final class RegistrationService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly TenantLicensingClient $tenantLicensing = new TenantLicensingClient(),
        private readonly TenantLicensingRepository $licensing = new TenantLicensingRepository(),
        private readonly RegistrationInviteRepository $invites = new RegistrationInviteRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly PersonalDataService $personalData = new PersonalDataService(),
        private readonly PdBootstrapService $pdBootstrap = new PdBootstrapService(),
        private readonly EntityMetaRepository $entityMeta = new EntityMetaRepository(),
        private readonly UserOrganizationService $organizations = new UserOrganizationService(),
    ) {
    }

    public function isEnabled(): bool
    {
        $configured = trim((string) ($_ENV['RBAC_REGISTRATION_ENABLED'] ?? ''));
        if ($configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'production')));

        return in_array($env, ['local', 'testing', 'test'], true);
    }

    public function register(array $input, array $server = []): array
    {
        if (!$this->isEnabled()) {
            return ['ok' => false, 'status' => 403, 'error' => 'Самостоятельная регистрация отключена'];
        }

        $phone = $this->resolvePhoneFromInput($input);
        $email = $this->normalizeEmail((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        $inviteToken = trim((string) ($input['invite_token'] ?? ''));

        $fieldError = $this->validateCommonFields($phone, $password, $email);
        if ($fieldError !== null) {
            return $fieldError;
        }

        if ($inviteToken !== '') {
            return $this->registerViaInvite($inviteToken, $email, $phone, $password, $input, $server);
        }

        if ($this->attemptedManualScopeJoin($input)) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Подключение к существующему workspace доступно только по ссылке-приглашению',
            ];
        }

        return $this->registerNewTenant($input, $email, $phone, $password, $server);
    }

    public function resolvePhoneFromInput(array $input): string
    {
        $phone = trim((string) ($input['phone'] ?? ''));
        if ($phone !== '') {
            return $this->normalizePhone($phone);
        }

        $prefix = trim((string) ($input['phone_prefix'] ?? ''));
        $number = trim((string) ($input['phone_number'] ?? ($input['phone_local'] ?? '')));
        if ($prefix === '' && $number === '') {
            return '';
        }

        return $this->normalizePhone($prefix . $number);
    }

    private function attemptedManualScopeJoin(array $input): bool
    {
        if (($input['flow'] ?? '') === 'existing_scope') {
            return true;
        }

        $tenantId = strtolower(trim((string) ($input['tenant_id'] ?? '')));
        $subtenantId = strtolower(trim((string) ($input['subtenant_id'] ?? '')));

        return $tenantId !== '' || $subtenantId !== '';
    }

    /**
     * @return array{ok: bool, status: int, invite_token?: string, register_url?: string, invite?: array}
     */
    public function createSubtenantInvite(string $tenantId, string $subtenantName, ?int $createdBy, ?string $roleCode = null): array
    {
        $tenantId = strtolower(trim($tenantId));
        $subtenantName = trim($subtenantName);
        if ($tenantId === '' || $subtenantName === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'tenant_id и subtenant_name обязательны'];
        }

        $tenantState = $this->licensing->accessState($tenantId, 'main');
        if (($tenantState['tenant_active'] ?? false) !== true) {
            return ['ok' => false, 'status' => 404, 'error' => 'Tenant не найден'];
        }

        $role = trim((string) ($roleCode ?? ($_ENV['RBAC_REGISTRATION_DEFAULT_ROLE'] ?? 'user')));
        if ($role === '') {
            $role = 'user';
        }

        $ttlHours = max(1, (int) ($_ENV['RBAC_REGISTRATION_INVITE_TTL_HOURS'] ?? 168));
        $rawToken = bin2hex(random_bytes(24));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $invite = $this->invites->create(
            $tenantId,
            $subtenantName,
            $role,
            $rawToken,
            $expiresAt,
            $createdBy,
            ['flow' => 'subtenant_invite']
        );

        $registerUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/register?invite=' . rawurlencode($rawToken);

        return [
            'ok' => true,
            'status' => 201,
            'invite_type' => 'subtenant',
            'invite_token' => $rawToken,
            'register_url' => $registerUrl,
            'invite' => [
                'id' => (int) ($invite['id'] ?? 0),
                'tenant_id' => $tenantId,
                'subtenant_name' => $subtenantName,
                'role_code' => $role,
                'expires_at' => $expiresAt,
            ],
        ];
    }

    /**
     * @return array{ok: bool, status: int, invite_token?: string, register_url?: string, invite?: array}
     */
    public function createUserInvite(string $tenantId, string $subtenantId, ?int $createdBy, ?string $roleCode = null): array
    {
        $tenantId = strtolower(trim($tenantId));
        $subtenantId = strtolower(trim($subtenantId));
        if ($tenantId === '' || $subtenantId === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'tenant_id и subtenant_id обязательны'];
        }

        $access = $this->tenantLicensing->assertAccess($tenantId, $subtenantId);
        if (($access['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 404),
                'error' => (string) ($access['error'] ?? 'Subtenant недоступен'),
            ];
        }

        $role = trim((string) ($roleCode ?? ($_ENV['RBAC_REGISTRATION_DEFAULT_ROLE'] ?? 'user')));
        if ($role === '') {
            $role = 'user';
        }

        $ttlHours = max(1, (int) ($_ENV['RBAC_REGISTRATION_INVITE_TTL_HOURS'] ?? 168));
        $rawToken = bin2hex(random_bytes(24));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600);

        $invite = $this->invites->create(
            $tenantId,
            $subtenantId,
            $role,
            $rawToken,
            $expiresAt,
            $createdBy,
            ['flow' => 'user_invite'],
            $subtenantId
        );

        $registerUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/register?invite=' . rawurlencode($rawToken);

        return [
            'ok' => true,
            'status' => 201,
            'invite_type' => 'user',
            'invite_token' => $rawToken,
            'register_url' => $registerUrl,
            'invite' => [
                'id' => (int) ($invite['id'] ?? 0),
                'tenant_id' => $tenantId,
                'subtenant_id' => $subtenantId,
                'role_code' => $role,
                'expires_at' => $expiresAt,
            ],
        ];
    }

    private function registerNewTenant(array $input, ?string $email, string $phone, string $password, array $server): array
    {
        $phoneConflict = $this->rejectIfPhoneAlreadyRegistered($phone);
        if ($phoneConflict !== null) {
            return $phoneConflict;
        }

        $tenantId = $this->generateTenantCode();
        $orgName = trim((string) ($input['organization_name'] ?? $input['organization'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        $tenantName = $orgName !== '' ? $orgName : ('Workspace ' . ($phoneDigits !== '' ? $phoneDigits : 'user'));
        $subtenantName = trim((string) ($_ENV['RBAC_REGISTRATION_DEFAULT_SUBTENANT_NAME'] ?? 'Main workspace'));
        $subtenantCode = strtolower(trim((string) ($_ENV['RBAC_REGISTRATION_DEFAULT_SUBTENANT_ID'] ?? 'main')));
        $actor = 'self_registration';

        $tenantResult = $this->licensing->createTenant($tenantId, $tenantName, $actor, ['source' => 'self_registration']);
        if (($tenantResult['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($tenantResult['status'] ?? 500),
                'error' => (string) ($tenantResult['error'] ?? 'Не удалось создать tenant'),
            ];
        }

        $this->pdBootstrap->seedTenant($tenantId, $tenantName);

        $subtenantResult = $this->licensing->createSubtenant($tenantId, $subtenantCode, $subtenantName, $actor, [
            'source' => 'self_registration',
        ]);
        if (($subtenantResult['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($subtenantResult['status'] ?? 500),
                'error' => (string) ($subtenantResult['error'] ?? 'Не удалось создать subtenant'),
            ];
        }

        $defaultProjects = new DefaultProjectService();
        $defaultProjects->ensureDefaultTenant($tenantId);
        $defaultProjects->ensureDefaultSubtenant($tenantId, $subtenantCode);

        $planCode = strtolower(trim((string) ($_ENV['RBAC_REGISTRATION_PLAN'] ?? 'starter')));
        $licenseResult = $this->licensing->assignLicense(
            $tenantId,
            $planCode,
            $actor,
            gmdate('Y-m-d H:i:s', strtotime('+365 days')),
            null
        );
        if (($licenseResult['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($licenseResult['status'] ?? 500),
                'error' => (string) ($licenseResult['error'] ?? 'Не удалось выдать лицензию'),
            ];
        }

        $platformDpaAccepted = filter_var($input['platform_dpa_accepted'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $selfSignDpa = filter_var($_ENV['RBAC_PD_DPA_SELF_SIGN_ON_REGISTER'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if ($platformDpaAccepted || $selfSignDpa) {
            $this->licensing->mergeTenantMetadata($tenantId, [
                'dpa_signed_at' => gmdate('Y-m-d H:i:s'),
                'dpa_source' => $platformDpaAccepted ? 'registration_acceptance' : 'registration_trial',
            ], $actor);
        }

        $bootstrapRole = trim((string) ($_ENV['RBAC_REGISTRATION_BOOTSTRAP_ROLE'] ?? 'tenant_admin'));
        if ($bootstrapRole === '') {
            $bootstrapRole = 'tenant_admin';
        }

        $consentItems = is_array($input['consents'] ?? null) ? $input['consents'] : [];

        $result = $this->createUserInScope(
            $tenantId,
            $subtenantCode,
            $email,
            $phone,
            $password,
            $bootstrapRole,
            $consentItems,
            $server
        );
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $result['tenant'] = [
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantCode,
            'plan_code' => $planCode,
        ];

        return $result;
    }

    private function registerViaInvite(
        string $inviteToken,
        ?string $email,
        string $phone,
        string $password,
        array $input,
        array $server
    ): array {
        if ($this->invites->isConsumedToken($inviteToken)) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'invite_already_used',
                'error' => 'Ссылка регистрации уже использована',
            ];
        }

        $invite = $this->invites->findPendingByToken($inviteToken);
        if ($invite === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Ссылка регистрации недействительна или истекла'];
        }

        $tenantId = (string) $invite['tenant_id'];
        $roleCode = (string) ($invite['role_code'] ?? 'user');
        $presetSubtenant = strtolower(trim((string) ($invite['subtenant_code'] ?? '')));
        $flow = $this->inviteFlow($invite);

        if ($flow === 'user_invite') {
            if ($presetSubtenant === '') {
                return ['ok' => false, 'status' => 422, 'error' => 'Приглашение пользователя некорректно'];
            }
            $subtenantCode = $presetSubtenant;
            (new DefaultProjectService())->ensureDefault($tenantId, $subtenantCode);
        } else {
            $subtenantCode = $this->generateSubtenantCode();
            $subtenantName = (string) $invite['subtenant_name'];
            $actor = 'registration_invite';
            $subtenantResult = $this->licensing->createSubtenant($tenantId, $subtenantCode, $subtenantName, $actor, [
                'source' => 'registration_invite',
                'invite_id' => (int) ($invite['id'] ?? 0),
            ]);
            if (($subtenantResult['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'status' => (int) ($subtenantResult['status'] ?? 500),
                    'error' => (string) ($subtenantResult['error'] ?? 'Не удалось создать subtenant'),
                ];
            }
            (new DefaultProjectService())->ensureDefault($tenantId, $subtenantCode);
        }

        $consentItems = is_array($input['consents'] ?? null) ? $input['consents'] : [];
        $attachExisting = $flow === 'user_invite' && $this->hasActiveAccountWithPhone($phone);
        $attachSources = [];

        if ($attachExisting) {
            $this->pdBootstrap->seedTenant($tenantId, 'Organization ' . $tenantId);
            $validated = $this->organizations->validateAttachViaInviteRegistration(
                $invite,
                $phone,
                $password,
                $consentItems
            );
            if (($validated['ok'] ?? false) !== true) {
                return $validated;
            }
            $attachSources = $validated['sources'] ?? [];
        }

        $claimed = $this->invites->claimPendingByToken($inviteToken, $subtenantCode);
        if ($claimed === null) {
            return [
                'ok' => false,
                'status' => 409,
                'code' => 'invite_already_used',
                'error' => 'Ссылка регистрации уже использована',
            ];
        }
        $invite = $claimed;

        if ($attachExisting) {
            $result = $this->organizations->attachAfterInviteClaim(
                $invite,
                $phone,
                $email,
                $consentItems,
                $server,
                $attachSources
            );
        } else {
            $result = $this->createUserInScope(
                $tenantId,
                $subtenantCode,
                $email,
                $phone,
                $password,
                $roleCode,
                $consentItems,
                $server
            );
        }

        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        $result['tenant'] = $result['tenant'] ?? [
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantCode,
            'invite_id' => (int) ($invite['id'] ?? 0),
        ];
        $result['attached'] = $attachExisting;

        return $result;
    }

    private function hasActiveAccountWithPhone(string $phone): bool
    {
        foreach ($this->users->findAllByPhone($phone) as $user) {
            if ((string) ($user['status'] ?? 'active') === 'active') {
                return true;
            }
        }

        return false;
    }

    private function createUserInScope(
        string $tenantId,
        string $subtenantId,
        ?string $email,
        string $phone,
        string $password,
        string $roleCode,
        array $consentItems = [],
        array $server = []
    ): array {
        $phoneConflict = $this->rejectIfPhoneAlreadyRegistered($phone);
        if ($phoneConflict !== null) {
            return $phoneConflict;
        }

        $login = $this->allocateLoginInScope($phone, $tenantId, $subtenantId);

        $consentError = $this->personalData->validateRegistrationConsents($tenantId, $consentItems);
        if ($consentError !== null) {
            return $consentError;
        }

        $access = $this->tenantLicensing->assertAccess($tenantId, $subtenantId);
        if (($access['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Tenant недоступен для регистрации'),
                'deny_reason' => $access['deny_reason'] ?? null,
            ];
        }

        $activeUsers = $this->users->countActiveUsers($tenantId, $subtenantId);
        $quota = $this->tenantLicensing->assertUserActivationAllowed($tenantId, $subtenantId, $activeUsers);
        if (($quota['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($quota['status'] ?? 402),
                'error' => (string) ($quota['error'] ?? 'Лимит пользователей по лицензии исчерпан'),
                'deny_reason' => $quota['deny_reason'] ?? 'seats_quota_exceeded',
            ];
        }

        if ($this->users->findByLogin($tenantId, $subtenantId, $login) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'Пользователь с таким login уже существует'];
        }

        try {
            $user = $this->users->createUser(
                $tenantId,
                $subtenantId,
                $login,
                $email,
                $phone,
                $this->passwords->hash($password),
                false,
                'active'
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Пользователь с таким login, email или телефоном уже существует'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка создания пользователя'];
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId > 0 && !$this->roles->assignRoleByCode($userId, $tenantId, $subtenantId, $roleCode, $userId)) {
            $this->securityEvents->write(
                'auth.register.role_assign_failed',
                $userId,
                $tenantId,
                $subtenantId,
                'warning',
                ['role_code' => $roleCode]
            );
        }

        $this->audit->write('auth.register', $userId, $tenantId, $subtenantId, [
            'login' => $login,
            'role_code' => $roleCode,
            'phone' => $phone,
        ]);
        $this->versioning->record(
            [
                'tenant_id' => $tenantId,
                'subtenant_id' => $subtenantId,
                'project_id' => null,
                'actor_user_id' => $userId > 0 ? $userId : null,
                'correlation_id' => null,
            ],
            'maniforge_users',
            (string) $userId,
            'insert',
            null,
            $user,
            $login
        );
        $this->securityEvents->write('auth.register.success', $userId, $tenantId, $subtenantId, 'info', [
            'login' => $login,
        ]);

        if ($userId > 0 && $consentItems !== []) {
            $this->personalData->recordRegistrationConsents($userId, $tenantId, $subtenantId, $consentItems, $server);
        }

        if ($userId > 0) {
            $this->entityMeta->rebindPhoneForUser($phone, $userId, $tenantId, $subtenantId);
        }

        return [
            'ok' => true,
            'status' => 201,
            'user' => $user,
            'role_code' => $roleCode,
        ];
    }

    /**
     * @return array{ok: false, status: int, error: string, code?: string}|null
     */
    private function rejectIfPhoneAlreadyRegistered(string $phone): ?array
    {
        if ($this->entityMeta->findGlobalPhoneUserId($phone) !== null) {
            return $this->phoneAlreadyRegisteredError();
        }

        foreach ($this->users->findAllByPhone($phone) as $user) {
            if ((string) ($user['status'] ?? 'active') === 'active') {
                return $this->phoneAlreadyRegisteredError();
            }
        }

        return null;
    }

    /**
     * @return array{ok: false, status: int, error: string, code: string}
     */
    private function phoneAlreadyRegisteredError(): array
    {
        return [
            'ok' => false,
            'status' => 409,
            'error' => 'Аккаунт с этим телефоном уже есть. Войдите и примите приглашение (accept-invite) или зарегистрируйтесь по ссылке invite с тем же паролем.',
            'code' => 'phone_already_registered',
        ];
    }

    private function validateCommonFields(string $phone, string $password, ?string $email): ?array
    {
        if ($phone === '' || $password === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'phone и password обязательны'];
        }

        if (!$this->isValidPhone($phone)) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Телефон: укажите код страны и номер (10–15 цифр в международном формате)',
            ];
        }

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'status' => 422, 'error' => 'Некорректный email'];
        }

        $minLength = (int) ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12);
        if (mb_strlen($password) < $minLength) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => "Пароль должен быть не короче {$minLength} символов",
            ];
        }

        return null;
    }

    private function generateTenantCode(): string
    {
        return 't-' . bin2hex(random_bytes(4));
    }

    private function generateSubtenantCode(): string
    {
        return 'st-' . bin2hex(random_bytes(4));
    }

    private function normalizeLogin(string $login): string
    {
        return strtolower(trim($login));
    }

    private function allocateLoginInScope(string $phone, string $tenantId, string $subtenantId): string
    {
        $base = $this->loginFromPhone($phone);
        $candidate = $base;
        $suffix = 2;
        while ($this->users->findByLogin($tenantId, $subtenantId, $candidate) !== null) {
            $tail = '_' . $suffix;
            $candidate = substr($base, 0, max(3, 64 - strlen($tail))) . $tail;
            $suffix++;
        }

        return $candidate;
    }

    private function loginFromPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return 'u' . bin2hex(random_bytes(4));
        }

        $login = 'u' . $digits;
        if (strlen($login) > 64) {
            $login = substr($login, 0, 64);
        }

        if ($this->isValidLogin($login)) {
            return $login;
        }

        return 'u' . substr($digits, 0, 62);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if ($digits === '') {
            return '';
        }

        return '+' . $digits;
    }

    private function normalizeEmail(string $email): ?string
    {
        $email = trim($email);

        return $email === '' ? null : strtolower($email);
    }

    private function isValidLogin(string $login): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,63}$/', $login) === 1;
    }

    private function isValidPhone(string $phone): bool
    {
        if (!str_starts_with($phone, '+')) {
            return false;
        }

        $digits = substr($phone, 1);

        return preg_match('/^\d{10,15}$/', $digits) === 1;
    }

    private function inviteFlow(array $invite): string
    {
        $metadata = $invite['metadata_json'] ?? null;
        if (is_string($metadata) && $metadata !== '') {
            $decoded = json_decode($metadata, true);
            if (is_array($decoded) && isset($decoded['flow']) && is_string($decoded['flow'])) {
                return $decoded['flow'];
            }
        }

        $presetSubtenant = strtolower(trim((string) ($invite['subtenant_code'] ?? '')));

        return $presetSubtenant !== '' ? 'user_invite' : 'subtenant_invite';
    }
}
