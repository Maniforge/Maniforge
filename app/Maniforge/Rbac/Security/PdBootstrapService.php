<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\PdOperatorProfileRepository;
use App\Maniforge\Rbac\Repository\PdPurposeRepository;

final class PdBootstrapService
{
    public function __construct(
        private readonly PdOperatorProfileRepository $profiles = new PdOperatorProfileRepository(),
        private readonly PdPurposeRepository $purposes = new PdPurposeRepository(),
    ) {
    }

    public function seedTenant(string $tenantId, string $operatorName): void
    {
        $tenantId = strtolower(trim($tenantId));
        if ($tenantId === '') {
            return;
        }

        if ($this->profiles->find($tenantId) === null) {
            $this->profiles->upsert($tenantId, [
                'operator_name' => $operatorName,
                'privacy_policy_url' => rtrim(trim((string) ($_ENV['APP_URL'] ?? 'http://127.0.0.1:8092')), '/') . '/docs/152FZ_COMPLIANCE.md',
                'privacy_policy_version' => '1.0',
                'data_storage_region' => 'RU',
                'cross_border_transfer_allowed' => false,
            ]);
        }

        foreach ($this->defaultPurposes() as $item) {
            if ($this->purposes->findByCode($tenantId, $item['code']) === null) {
                $this->purposes->create($tenantId, $item);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultPurposes(): array
    {
        return [
            [
                'code' => 'account',
                'title' => 'Учётная запись и аутентификация',
                'description' => 'Регистрация, вход, восстановление доступа',
                'legal_basis' => 'contract',
                'retention_days' => 1825,
                'is_mandatory_for_registration' => true,
                'policy_version' => '1.0',
            ],
            [
                'code' => 'support',
                'title' => 'Поддержка пользователей',
                'legal_basis' => 'legitimate_interest',
                'retention_days' => 1095,
                'is_mandatory_for_registration' => false,
                'policy_version' => '1.0',
            ],
        ];
    }
}
