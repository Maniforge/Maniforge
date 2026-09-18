<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\PdConsentRepository;
use App\Maniforge\Rbac\Repository\PdOperatorProfileRepository;
use App\Maniforge\Rbac\Repository\PdPurposeRepository;
use App\Maniforge\Rbac\Repository\PdSubjectRequestRepository;
use App\Maniforge\Rbac\Repository\UserRepository;

final class PersonalDataService
{
    public function __construct(
        private readonly PdOperatorProfileRepository $operators = new PdOperatorProfileRepository(),
        private readonly PdPurposeRepository $purposes = new PdPurposeRepository(),
        private readonly PdConsentRepository $consents = new PdConsentRepository(),
        private readonly PdSubjectRequestRepository $requests = new PdSubjectRequestRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SessionService $sessionService = new SessionService(),
    ) {
    }

    public function resolveTenantFromServer(array $server, array $input = []): array
    {
        $mode = strtolower((string) ($_ENV['TENANCY_MODE'] ?? 'single'));
        $defaultTenant = strtolower(trim((string) ($_ENV['DEFAULT_TENANT_ID'] ?? 'default')));
        $defaultSubtenant = strtolower(trim((string) ($_ENV['DEFAULT_SUBTENANT_ID'] ?? 'default')));

        if ($mode === 'single' || $mode === 'disabled') {
            return [
                'ok' => true,
                'tenant_id' => $defaultTenant,
                'subtenant_id' => $defaultSubtenant,
            ];
        }

        $tenantId = strtolower(trim((string) ($server['HTTP_X_TENANT_ID'] ?? ($input['tenant_id'] ?? ''))));
        $subtenantId = strtolower(trim((string) ($server['HTTP_X_SUBTENANT_ID'] ?? ($input['subtenant_id'] ?? ''))));

        if ($tenantId === '' || $subtenantId === '') {
            return [
                'ok' => false,
                'status' => 400,
                'error' => 'Для privacy API укажите X-Tenant-ID и X-Subtenant-ID',
            ];
        }

        return ['ok' => true, 'tenant_id' => $tenantId, 'subtenant_id' => $subtenantId];
    }

    public function buildPrivacyNotice(string $tenantId): array
    {
        $profile = $this->operators->find($tenantId);
        $purposeItems = $this->purposes->listActive($tenantId);
        $minPurposes = (int) ($_ENV['RBAC_PD_NOTICE_MIN_PURPOSES'] ?? 1);

        if ($profile === null) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Профиль оператора ПДн не настроен для tenant',
            ];
        }

        if (count($purposeItems) < $minPurposes) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'Цели обработки ПДн не опубликованы',
            ];
        }

        $notice = [
            'operator' => [
                    'name' => $profile['operator_name'] ?? '',
                    'inn' => $profile['operator_inn'] ?? null,
                    'address' => $profile['operator_address'] ?? null,
                    'dpo' => [
                        'name' => $profile['dpo_name'] ?? null,
                        'email' => $profile['dpo_email'] ?? null,
                        'phone' => $profile['dpo_phone'] ?? null,
                    ],
                ],
                'privacy_policy_url' => $profile['privacy_policy_url'] ?? null,
                'privacy_policy_version' => $profile['privacy_policy_version'] ?? '1.0',
                'data_storage_region' => $profile['data_storage_region'] ?? 'RU',
                'cross_border_transfer_allowed' => (bool) ($profile['cross_border_transfer_allowed'] ?? false),
                'cross_border_basis' => $profile['cross_border_basis'] ?? null,
                'processing_purposes' => array_map(static function (array $p): array {
                    return [
                        'code' => $p['code'],
                        'title' => $p['title'],
                        'description' => $p['description'] ?? null,
                        'legal_basis' => $p['legal_basis'],
                        'retention_days' => $p['retention_days'] ?? null,
                        'policy_version' => $p['policy_version'],
                        'mandatory_for_registration' => (bool) ($p['is_mandatory_for_registration'] ?? false),
                    ];
                }, $purposeItems),
                'subject_rights_hint' => 'Запросы: GET/POST /api/v1/me/personal-data/subject-requests (при авторизации)',
        ];

        $processor = (new PlatformProcessorConfig())->toNoticePayload();
        if ($processor !== null) {
            $notice['processor'] = $processor;
        }

        return [
            'ok' => true,
            'status' => 200,
            'notice' => $notice,
        ];
    }

    public function exportForUser(array $session): array
    {
        $userId = (int) $session['user_id'];
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];

        $user = $this->users->findByIdInScope($userId, $tenantId, $subtenantId);
        if ($user === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        $profile = $this->fetchProfile($userId);

        return [
            'ok' => true,
            'status' => 200,
            'exported_at' => gmdate('c'),
            'data' => [
                'user' => $user,
                'profile' => $profile,
                'consents' => $this->consents->listForUser($userId, $tenantId, $subtenantId),
                'subject_requests' => $this->requests->listForUser($userId, $tenantId, $subtenantId, 50),
                'sessions_summary' => $this->sessionsSummary($userId, $tenantId, $subtenantId),
                'audit_recent' => $this->audit->listForActor($userId, $tenantId, $subtenantId, 30),
            ],
        ];
    }

    /**
     * Оставляет только цели, настроенные в tenant (для привязки к второй организации).
     *
     * @param list<array{purpose_code?: string, code?: string, policy_version?: string}> $consentItems
     * @return list<array{purpose_code?: string, code?: string, policy_version?: string}>
     */
    public function filterConsentsKnownInTenant(string $tenantId, array $consentItems): array
    {
        $filtered = [];
        foreach ($consentItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = trim((string) ($item['purpose_code'] ?? $item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $purpose = $this->purposes->findByCode($tenantId, $code);
            if ($purpose !== null && (int) ($purpose['is_active'] ?? 0) === 1) {
                $filtered[] = $item;
            }
        }

        return $filtered;
    }

    /**
     * @param list<array{purpose_code?: string, code?: string, policy_version?: string}> $consentItems
     */
    public function validateRegistrationConsents(string $tenantId, array $consentItems): ?array
    {
        $required = filter_var($_ENV['RBAC_PD_REGISTER_CONSENT_REQUIRED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $mandatory = $this->purposes->listMandatoryForRegistration($tenantId);

        if ($required && $mandatory === []) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Для tenant не настроены обязательные цели обработки (purposes)',
            ];
        }

        $provided = $this->normalizeConsentItems($consentItems);

        foreach ($mandatory as $m) {
            $code = (string) ($m['code'] ?? '');
            $expectedVersion = (string) ($m['policy_version'] ?? '1.0');
            if ($code === '') {
                continue;
            }
            if (!isset($provided[$code])) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'Необходимо согласие для цели: ' . $code,
                    'missing_purpose' => $code,
                ];
            }
            if ($required && $provided[$code] !== $expectedVersion) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'Устаревшая версия политики для цели: ' . $code,
                    'expected_policy_version' => $expectedVersion,
                ];
            }
        }

        foreach ($provided as $code => $version) {
            $purpose = $this->purposes->findByCode($tenantId, $code);
            if ($purpose === null || (int) ($purpose['is_active'] ?? 0) !== 1) {
                return ['ok' => false, 'status' => 422, 'error' => 'Неизвестная цель обработки: ' . $code];
            }
            if ($version !== (string) ($purpose['policy_version'] ?? '1.0') && $required) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => 'Версия политики не совпадает с опубликованной для: ' . $code,
                ];
            }
        }

        return null;
    }

    /**
     * @param list<array{purpose_code?: string, code?: string, policy_version?: string}> $consentItems
     */
    public function recordRegistrationConsents(
        int $userId,
        string $tenantId,
        string $subtenantId,
        array $consentItems,
        array $server
    ): void {
        $provided = $this->normalizeConsentItems($consentItems);
        if ($provided === []) {
            return;
        }

        $ipHash = hash('sha256', (string) ($server['REMOTE_ADDR'] ?? 'unknown'));
        $uaHash = hash('sha256', (string) ($server['HTTP_USER_AGENT'] ?? 'unknown'));

        foreach ($provided as $code => $version) {
            $this->consents->grant(
                $userId,
                $tenantId,
                $subtenantId,
                $code,
                $version,
                'registration',
                $ipHash,
                $uaHash
            );
        }
    }

    /**
     * @param list<array{purpose_code?: string, code?: string, policy_version?: string}> $consentItems
     * @return array<string, string>
     */
    private function normalizeConsentItems(array $consentItems): array
    {
        $provided = [];
        foreach ($consentItems as $item) {
            if (!is_array($item)) {
                continue;
            }
            $code = $this->purposes->normalizeCode((string) ($item['purpose_code'] ?? $item['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $provided[$code] = trim((string) ($item['policy_version'] ?? '1.0')) ?: '1.0';
        }

        return $provided;
    }

    public function grantConsent(array $session, string $purposeCode, string $policyVersion, array $server): array
    {
        $tenantId = (string) $session['tenant_id'];
        $purpose = $this->purposes->findByCode($tenantId, $purposeCode);
        if ($purpose === null || (int) ($purpose['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'status' => 404, 'error' => 'Цель обработки не найдена'];
        }

        $row = $this->consents->grant(
            (int) $session['user_id'],
            $tenantId,
            (string) $session['subtenant_id'],
            $purposeCode,
            $policyVersion !== '' ? $policyVersion : (string) ($purpose['policy_version'] ?? '1.0'),
            'api',
            hash('sha256', (string) ($server['REMOTE_ADDR'] ?? 'unknown')),
            hash('sha256', (string) ($server['HTTP_USER_AGENT'] ?? 'unknown'))
        );

        return ['ok' => true, 'status' => 201, 'consent' => $row];
    }

    public function revokeConsent(array $session, string $purposeCode): array
    {
        $this->consents->revokeActive(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $purposeCode
        );

        return ['ok' => true, 'status' => 200];
    }

    public function createSubjectRequest(array $session, string $requestType, ?array $payload): array
    {
        if (!in_array($requestType, PdSubjectRequestRepository::TYPES, true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Некорректный request_type'];
        }

        $slaDays = max(1, (int) ($_ENV['RBAC_PD_REQUEST_SLA_DAYS'] ?? 30));
        $dueAt = gmdate('Y-m-d H:i:s', time() + $slaDays * 86400);

        $row = $this->requests->create(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            $requestType,
            $payload,
            $dueAt
        );

        return ['ok' => true, 'status' => 201, 'request' => $row];
    }

    public function resolveSubjectRequest(
        array $session,
        int $requestId,
        string $status,
        ?string $handlerNote
    ): array {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $existing = $this->requests->findById($requestId);
        if ($existing === null
            || (string) ($existing['tenant_id'] ?? '') !== $tenantId
            || (string) ($existing['subtenant_id'] ?? '') !== $subtenantId
        ) {
            return ['ok' => false, 'status' => 404, 'error' => 'Запрос не найден'];
        }

        $updated = $this->requests->resolve(
            $requestId,
            $tenantId,
            $subtenantId,
            $status,
            (int) $session['user_id'],
            $handlerNote
        );
        if ($updated === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Не удалось обновить запрос'];
        }

        if ($status === 'completed' && (string) ($existing['request_type'] ?? '') === 'erasure') {
            $this->applyErasure((int) ($existing['user_id'] ?? 0), $tenantId, $subtenantId);
        }

        if ($status === 'completed' && (string) ($existing['request_type'] ?? '') === 'withdraw_consent') {
            $payload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
            $code = (string) ($payload['purpose_code'] ?? '');
            if ($code !== '') {
                $this->consents->revokeActive((int) $existing['user_id'], $tenantId, $subtenantId, $code);
            }
        }

        return ['ok' => true, 'status' => 200, 'request' => $updated];
    }

    private function applyErasure(int $userId, string $tenantId, string $subtenantId): void
    {
        if ($userId <= 0) {
            return;
        }

        $erasedPhone = '+7000' . str_pad((string) $userId, 7, '0', STR_PAD_LEFT);
        $this->users->updateUserInScope($userId, $tenantId, $subtenantId, [
            'status' => 'disabled',
            'email' => null,
            'phone' => $erasedPhone,
            'login' => 'erased_' . $userId,
        ]);

        $this->sessionService->revokeAllForUser($userId, 'pd.erasure');
    }

    private function fetchProfile(int $userId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT display_name, avatar_url, bio, locale, timezone, updated_at
             FROM maniforge_user_profile WHERE user_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function sessionsSummary(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, created_at, last_activity_at, expires_at
             FROM maniforge_sessions
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
               AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()
             ORDER BY last_activity_at DESC
             LIMIT 20'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $items = $stmt->fetchAll() ?: [];

        return [
            'active_count' => count($items),
            'items' => $items,
        ];
    }
}
