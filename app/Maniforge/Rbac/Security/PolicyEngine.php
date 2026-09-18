<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\PolicyRuleRepository;

final class PolicyEngine
{
    public function __construct(
        private readonly PolicyRuleRepository $rules = new PolicyRuleRepository()
    ) {
    }

    public function allowsAdminAction(array $server, string $tenantId, string $subtenantId): array
    {
        $effective = $this->getEffectiveAdminRules($tenantId, $subtenantId);
        $ip = (string) ($server['REMOTE_ADDR'] ?? '');
        $allowedIps = $effective['allowed_ips'];

        if ($allowedIps !== [] && !in_array($ip, $allowedIps, true)) {
            return ['ok' => false, 'error' => 'IP не разрешен для admin-операций'];
        }

        $hour = (int) gmdate('G');
        $start = (int) $effective['allowed_hour_start_utc'];
        $end = (int) $effective['allowed_hour_end_utc'];
        if ($hour < $start || $hour > $end) {
            return ['ok' => false, 'error' => 'Вне разрешенного временного окна для admin-операций'];
        }

        return ['ok' => true];
    }

    public function requiresStepUp(string $tenantId, string $subtenantId): bool
    {
        $effective = $this->getEffectiveAdminRules($tenantId, $subtenantId);

        return (bool) $effective['require_step_up'];
    }

    public function getEffectiveAdminRules(string $tenantId, string $subtenantId): array
    {
        $dbRule = $this->rules->getForScope($tenantId, $subtenantId);
        if ($dbRule !== null) {
            $dbRule['source'] = 'db';
            return $dbRule;
        }

        $allowedIps = array_values(
            array_filter(array_map('trim', explode(',', (string) ($_ENV['RBAC_ADMIN_ALLOWED_IPS'] ?? ''))))
        );

        return [
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantId,
            'allowed_ips' => $allowedIps,
            'allowed_hour_start_utc' => (int) ($_ENV['RBAC_ADMIN_ALLOWED_HOUR_START_UTC'] ?? 0),
            'allowed_hour_end_utc' => (int) ($_ENV['RBAC_ADMIN_ALLOWED_HOUR_END_UTC'] ?? 23),
            'require_step_up' => filter_var($_ENV['RBAC_ADMIN_REQUIRE_STEP_UP'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'source' => 'env',
        ];
    }
}
