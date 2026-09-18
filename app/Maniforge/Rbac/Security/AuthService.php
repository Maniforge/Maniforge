<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\LoginAttemptRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Repository\SessionRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Support\PublicUserPayload;

final class AuthService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly SessionRepository $sessionRecords = new SessionRepository(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly SessionService $sessions = new SessionService(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly LoginAttemptRepository $attempts = new LoginAttemptRepository(),
        private readonly TenantLicensingClient $tenantLicensing = new TenantLicensingClient(),
        private readonly TenantPdComplianceService $pdCompliance = new TenantPdComplianceService(),
        private readonly RegistrationService $registration = new RegistrationService(),
    ) {
    }

    public function login(array $tenant, array $input, array $server): array
    {
        $password = (string) ($input['password'] ?? '');
        $phone = $this->registration->resolvePhoneFromInput($input);

        if ($phone === '' || $password === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'Телефон и пароль обязательны'];
        }

        return $this->loginByPhone($phone, $password, $input, $server);
    }

    private function loginByPhone(string $phone, string $password, array $input, array $server): array
    {
        if ($password === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'Телефон и пароль обязательны'];
        }

        if (!str_starts_with($phone, '+') || preg_match('/^\+\d{10,15}$/', $phone) !== 1) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Телефон: укажите код страны и номер (10–15 цифр в международном формате)',
            ];
        }

        $matches = $this->users->findAllByPhone($phone);
        $matches = array_values(array_filter(
            $matches,
            static fn (array $user): bool => (string) ($user['status'] ?? '') === 'active'
        ));
        $hintTenant = strtolower(trim((string) ($input['tenant_id'] ?? '')));
        $hintSubtenant = strtolower(trim((string) ($input['subtenant_id'] ?? '')));
        if ($hintTenant !== '' && $hintSubtenant !== '') {
            $matches = array_values(array_filter(
                $matches,
                static fn (array $user): bool => (string) ($user['tenant_id'] ?? '') === $hintTenant
                    && (string) ($user['subtenant_id'] ?? '') === $hintSubtenant
            ));
        }

        if ($matches === []) {
            return $this->failedPhoneLogin($phone, ['tenant_id' => '', 'subtenant_id' => ''], $server);
        }

        $user = count($matches) === 1
            ? $matches[0]
            : $this->resolvePhoneLoginUser($matches, $password);
        if ($user === null) {
            return $this->failedPhoneLogin($phone, ['tenant_id' => '', 'subtenant_id' => ''], $server);
        }
        $scope = [
            'tenant_id' => (string) ($user['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($user['subtenant_id'] ?? ''),
        ];

        return $this->authenticateInScope($scope, $phone, $password, $server, 'phone', $user);
    }

    /**
     * @param array<string, mixed>|null $knownUser
     */
    private function authenticateInScope(
        array $tenant,
        string $credentialKey,
        string $password,
        array $server,
        string $credentialType,
        ?array $knownUser = null,
    ): array {
        $tenantId = (string) ($tenant['tenant_id'] ?? '');
        $subtenantId = (string) ($tenant['subtenant_id'] ?? '');
        if ($tenantId === '' || $subtenantId === '') {
            return ['ok' => false, 'status' => 400, 'error' => 'Не удалось определить область входа'];
        }

        $access = $this->tenantLicensing->assertAccess($tenantId, $subtenantId);
        if (!$access['ok']) {
            $this->securityEvents->write(
                'auth.login.tenant_license_blocked',
                null,
                $tenantId,
                $subtenantId,
                'warning',
                ['reason' => (string) ($access['deny_reason'] ?? 'tenant_license_unavailable')]
            );

            return [
                'ok' => false,
                'status' => (int) ($access['status'] ?? 403),
                'error' => (string) ($access['error'] ?? 'Tenant недоступен'),
            ];
        }

        $ip = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $activeLock = $this->attempts->activeLock($tenantId, $subtenantId, $credentialKey, $ip);
        if ($activeLock !== null) {
            return [
                'ok' => false,
                'status' => 429,
                'error' => 'Слишком много неудачных попыток. Вход временно заблокирован',
                'locked_until' => $activeLock['locked_until'],
            ];
        }

        if ($knownUser === null) {
            $knownUser = $this->users->findByLogin($tenantId, $subtenantId, $credentialKey);
        }

        if ($knownUser === null || !$this->passwords->verify($password, (string) ($knownUser['password_hash'] ?? ''))) {
            if ($credentialType === 'phone') {
                return $this->failedPhoneLogin($credentialKey, $tenant, $server);
            }

            $attempt = $this->attempts->registerFailure($tenantId, $subtenantId, $credentialKey, $ip);
            $this->securityEvents->write(
                'auth.login.failed',
                null,
                $tenantId,
                $subtenantId,
                'warning',
                [
                    'login' => $credentialKey,
                    'failed_count' => $attempt['failed_count'],
                    'locked_until' => $attempt['locked_until'],
                ]
            );

            return ['ok' => false, 'status' => 401, 'error' => 'Неверные учетные данные'];
        }

        $user = $knownUser;

        if (($user['status'] ?? 'active') !== 'active') {
            $this->securityEvents->write(
                'auth.login.blocked',
                (int) $user['id'],
                $tenantId,
                $subtenantId,
                'warning',
                ['status' => (string) ($user['status'] ?? 'unknown')]
            );

            return ['ok' => false, 'status' => 403, 'error' => 'Аккаунт не активен'];
        }

        $this->attempts->clear($tenantId, $subtenantId, $credentialKey, $ip);

        $compliance = $this->pdCompliance->assertLoginAllowed(
            $tenantId,
            (int) $user['id'],
            $subtenantId
        );
        if (($compliance['ok'] ?? false) !== true) {
            $this->securityEvents->write(
                'auth.login.pd_compliance_blocked',
                (int) $user['id'],
                $tenantId,
                $subtenantId,
                'warning',
                ['reason' => (string) ($compliance['deny_reason'] ?? 'pd_compliance')]
            );

            return [
                'ok' => false,
                'status' => (int) ($compliance['status'] ?? 403),
                'error' => (string) ($compliance['error'] ?? 'Compliance не выполнен'),
                'deny_reason' => (string) ($compliance['deny_reason'] ?? 'pd_compliance'),
                'compliance' => $compliance['compliance'] ?? null,
            ];
        }

        $session = $this->sessions->issue($user, $tenant, $server);
        $this->audit->write('auth.login.success', (int) $user['id'], $tenantId, $subtenantId, [
            'session_id' => $session['session_id'],
            'credential_type' => $credentialType,
        ]);
        $this->securityEvents->write(
            'auth.login.success',
            (int) $user['id'],
            $tenantId,
            $subtenantId,
            'info',
            ['session_id' => $session['session_id'], 'credential_type' => $credentialType]
        );

        $response = [
            'ok' => true,
            'status' => 200,
            'user' => PublicUserPayload::fromUser($user),
            'credentials' => [
                'session' => $session,
            ],
            'session' => $session,
        ];
        if (isset($compliance['compliance_warning'])) {
            $response['compliance_warning'] = (string) $compliance['compliance_warning'];
            $response['compliance'] = $compliance['compliance'] ?? null;
        }

        return $response;
    }

    private function failedPhoneLogin(string $phone, array $tenant, array $server): array
    {
        $tenantId = (string) ($tenant['tenant_id'] ?? '');
        $subtenantId = (string) ($tenant['subtenant_id'] ?? '');
        $ip = (string) ($server['REMOTE_ADDR'] ?? 'unknown');

        if ($tenantId !== '' && $subtenantId !== '') {
            $attempt = $this->attempts->registerFailure($tenantId, $subtenantId, $phone, $ip);
            $this->securityEvents->write(
                'auth.login.failed',
                null,
                $tenantId,
                $subtenantId,
                'warning',
                [
                    'phone' => $phone,
                    'failed_count' => $attempt['failed_count'],
                    'locked_until' => $attempt['locked_until'],
                ]
            );
        } else {
            $this->securityEvents->write(
                'auth.login.failed',
                null,
                '',
                '',
                'warning',
                ['phone' => $phone, 'scope_unknown' => true]
            );
        }

        return ['ok' => false, 'status' => 401, 'error' => 'Неверные учетные данные'];
    }

    /**
     * @param list<array<string, mixed>> $matches
     */
    private function resolvePhoneLoginUser(array $matches, string $password): ?array
    {
        $passwordMatches = [];
        foreach ($matches as $user) {
            if ($this->passwords->verify($password, (string) ($user['password_hash'] ?? ''))) {
                $passwordMatches[] = $user;
            }
        }

        if ($passwordMatches === []) {
            return null;
        }

        if (count($passwordMatches) === 1) {
            return $passwordMatches[0];
        }

        return $this->pickUserByLastSuccessfulLogin($passwordMatches);
    }

    /**
     * @param list<array<string, mixed>> $users
     */
    private function pickUserByLastSuccessfulLogin(array $users): array
    {
        $scopes = array_map(
            static fn (array $user): array => [
                'user_id' => (int) ($user['id'] ?? 0),
                'tenant_id' => (string) ($user['tenant_id'] ?? ''),
                'subtenant_id' => (string) ($user['subtenant_id'] ?? ''),
            ],
            $users
        );

        $lastLoginAt = $this->mergeLastLoginTimestamps(
            $this->sessionRecords->findLastLoginAtByUserScopes($scopes),
            $this->audit->findLastLoginSuccessAtByUserScopes($scopes),
            $this->securityEvents->findLastLoginSuccessAtByUserScopes($scopes),
        );

        usort(
            $users,
            function (array $left, array $right) use ($lastLoginAt): int {
                $leftKey = SessionRepository::userScopeKey(
                    (int) ($left['id'] ?? 0),
                    (string) ($left['tenant_id'] ?? ''),
                    (string) ($left['subtenant_id'] ?? '')
                );
                $rightKey = SessionRepository::userScopeKey(
                    (int) ($right['id'] ?? 0),
                    (string) ($right['tenant_id'] ?? ''),
                    (string) ($right['subtenant_id'] ?? '')
                );

                $leftLogin = $lastLoginAt[$leftKey] ?? null;
                $rightLogin = $lastLoginAt[$rightKey] ?? null;
                if ($leftLogin !== null && $rightLogin !== null && $leftLogin !== $rightLogin) {
                    return $rightLogin <=> $leftLogin;
                }
                if ($leftLogin !== null && $rightLogin === null) {
                    return -1;
                }
                if ($leftLogin === null && $rightLogin !== null) {
                    return 1;
                }

                $leftUpdated = (string) ($left['updated_at'] ?? '');
                $rightUpdated = (string) ($right['updated_at'] ?? '');
                if ($leftUpdated !== $rightUpdated) {
                    return $rightUpdated <=> $leftUpdated;
                }

                return (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0);
            }
        );

        return $users[0];
    }

    /**
     * @param array<string, string> ...$maps
     * @return array<string, string>
     */
    private function mergeLastLoginTimestamps(array ...$maps): array
    {
        $merged = [];
        foreach ($maps as $map) {
            foreach ($map as $key => $timestamp) {
                if (!isset($merged[$key]) || $timestamp > $merged[$key]) {
                    $merged[$key] = $timestamp;
                }
            }
        }

        return $merged;
    }
}
