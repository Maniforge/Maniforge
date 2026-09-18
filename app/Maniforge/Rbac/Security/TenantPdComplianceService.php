<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\PdOperatorProfileRepository;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

final class TenantPdComplianceService
{
    public function __construct(
        private readonly TenantLicensingRepository $licensing = new TenantLicensingRepository(),
        private readonly PdOperatorProfileRepository $operators = new PdOperatorProfileRepository(),
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    public function isEnforced(): bool
    {
        if (!filter_var($_ENV['RBAC_PD_DPA_REQUIRED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? 'local')));

        return in_array($env, ['prod', 'production'], true);
    }

    public function isExemptTenant(string $tenantId): bool
    {
        $raw = (string) ($_ENV['RBAC_PD_DPA_EXEMPT_TENANTS'] ?? 'demo,default');
        $list = array_filter(array_map(static fn (string $v): string => strtolower(trim($v)), explode(',', $raw)));

        return in_array(strtolower(trim($tenantId)), $list, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildStatus(string $tenantId): array
    {
        $tenantId = strtolower(trim($tenantId));
        $profile = $this->operators->find($tenantId);
        $readiness = $this->operatorReadiness($profile);
        $tenantMeta = $this->licensing->getTenantMetadata($tenantId);
        $processor = (new PlatformProcessorConfig())->toNoticePayload();

        return [
            'enforced' => $this->isEnforced(),
            'exempt' => $this->isExemptTenant($tenantId),
            'dpa_signed_at' => $tenantMeta['dpa_signed_at'] ?? null,
            'dpa_accepted_at' => is_array($profile['metadata'] ?? null)
                ? ($profile['metadata']['dpa_accepted_at'] ?? null)
                : null,
            'operator_ready' => $readiness['ready'],
            'operator_missing' => $readiness['missing'],
            'processor_configured' => $processor !== null,
            'processor' => $processor,
            'ready_for_users' => $this->isTenantReadyForRegularUsers($tenantId, $profile, $tenantMeta),
        ];
    }

    /**
     * @return array{ok: bool, status?: int, error?: string, deny_reason?: string, compliance?: array<string, mixed>}
     */
    public function assertLoginAllowed(string $tenantId, int $userId, string $subtenantId): array
    {
        if (!$this->isEnforced() || $this->isExemptTenant($tenantId)) {
            return ['ok' => true];
        }

        $status = $this->buildStatus($tenantId);
        if (($status['dpa_signed_at'] ?? '') === '') {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Договор поручения обработки ПДн (DPA) не активирован для организации',
                'deny_reason' => 'pd_dpa_unsigned',
                'compliance' => $status,
            ];
        }

        if (($status['operator_ready'] ?? false) === true) {
            return ['ok' => true, 'compliance' => $status];
        }

        if ($this->canManageOperatorProfile($userId, $tenantId, $subtenantId)) {
            return [
                'ok' => true,
                'compliance' => $status,
                'compliance_warning' => 'pd_operator_incomplete',
            ];
        }

        return [
            'ok' => false,
            'status' => 403,
            'error' => 'Оператор ПДн не завершил настройку compliance. Обратитесь к администратору организации.',
            'deny_reason' => 'pd_operator_not_ready',
            'compliance' => $status,
        ];
    }

    public function recordDpaAcceptance(string $tenantId, int $userId): array
    {
        $profile = $this->operators->find($tenantId);
        if ($profile === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Профиль оператора не найден'];
        }

        $meta = is_array($profile['metadata'] ?? null) ? $profile['metadata'] : [];
        $meta['dpa_accepted_at'] = gmdate('Y-m-d H:i:s');
        $meta['dpa_accepted_by_user_id'] = $userId;

        $updated = $this->operators->upsert($tenantId, array_merge($profile, ['metadata' => $meta]));

        return ['ok' => true, 'profile' => $updated];
    }

    /**
     * @param array<string, mixed>|null $profile
     * @return array{ready: bool, missing: list<string>}
     */
    private function operatorReadiness(?array $profile): array
    {
        if ($profile === null) {
            return ['ready' => false, 'missing' => ['operator_profile']];
        }

        $missing = [];
        if (trim((string) ($profile['operator_name'] ?? '')) === '') {
            $missing[] = 'operator_name';
        }
        if (trim((string) ($profile['privacy_policy_url'] ?? '')) === '') {
            $missing[] = 'privacy_policy_url';
        }
        if (trim((string) ($profile['dpo_email'] ?? '')) === '') {
            $missing[] = 'dpo_email';
        }
        if (strtoupper(trim((string) ($profile['data_storage_region'] ?? ''))) !== 'RU') {
            $missing[] = 'data_storage_region_ru';
        }

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * @param array<string, mixed>|null $profile
     * @param array<string, mixed> $tenantMeta
     */
    private function isTenantReadyForRegularUsers(string $tenantId, ?array $profile, array $tenantMeta): bool
    {
        if ($this->isExemptTenant($tenantId)) {
            return true;
        }
        if (($tenantMeta['dpa_signed_at'] ?? '') === '') {
            return false;
        }

        return $this->operatorReadiness($profile)['ready'];
    }

    private function canManageOperatorProfile(int $userId, string $tenantId, string $subtenantId): bool
    {
        if ($this->rbac->hasAnyRole($userId, $tenantId, $subtenantId, ['tenant_admin', 'platform_admin'])) {
            return true;
        }

        return $this->rbac->hasPermission($userId, $tenantId, $subtenantId, 'admin.pd.operator.write');
    }
}
