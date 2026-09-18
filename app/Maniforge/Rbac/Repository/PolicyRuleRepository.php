<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PolicyRuleRepository
{
    public function getForScope(string $tenantId, string $subtenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT tenant_id, subtenant_id, allowed_ips_json, allowed_hour_start_utc, allowed_hour_end_utc, require_step_up
             FROM maniforge_policy_rules
             WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $decodedIps = json_decode((string) ($row['allowed_ips_json'] ?? '[]'), true);
        $allowedIps = [];
        if (is_array($decodedIps)) {
            foreach ($decodedIps as $ip) {
                $value = trim((string) $ip);
                if ($value !== '') {
                    $allowedIps[] = $value;
                }
            }
        }

        return [
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($row['subtenant_id'] ?? ''),
            'allowed_ips' => $allowedIps,
            'allowed_hour_start_utc' => (int) ($row['allowed_hour_start_utc'] ?? 0),
            'allowed_hour_end_utc' => (int) ($row['allowed_hour_end_utc'] ?? 23),
            'require_step_up' => (bool) ((int) ($row['require_step_up'] ?? 1)),
        ];
    }

    public function upsertForScope(
        string $tenantId,
        string $subtenantId,
        array $allowedIps,
        int $allowedHourStartUtc,
        int $allowedHourEndUtc,
        bool $requireStepUp,
        int $updatedBy
    ): void {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_policy_rules (
                tenant_id,
                subtenant_id,
                allowed_ips_json,
                allowed_hour_start_utc,
                allowed_hour_end_utc,
                require_step_up,
                updated_by,
                created_at,
                updated_at
            ) VALUES (
                :tenant_id,
                :subtenant_id,
                :allowed_ips_json,
                :allowed_hour_start_utc,
                :allowed_hour_end_utc,
                :require_step_up,
                :updated_by,
                UTC_TIMESTAMP(),
                UTC_TIMESTAMP()
            )
            ON DUPLICATE KEY UPDATE
                allowed_ips_json = VALUES(allowed_ips_json),
                allowed_hour_start_utc = VALUES(allowed_hour_start_utc),
                allowed_hour_end_utc = VALUES(allowed_hour_end_utc),
                require_step_up = VALUES(require_step_up),
                updated_by = VALUES(updated_by),
                updated_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':allowed_ips_json' => json_encode(array_values($allowedIps), JSON_UNESCAPED_UNICODE),
            ':allowed_hour_start_utc' => $allowedHourStartUtc,
            ':allowed_hour_end_utc' => $allowedHourEndUtc,
            ':require_step_up' => $requireStepUp ? 1 : 0,
            ':updated_by' => $updatedBy,
        ]);
    }
}
