<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class TenantAccessCacheRepository
{
    public function get(string $tenantCode, string $subtenantCode): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT state_json
             FROM maniforge_tenant_access_cache
             WHERE cache_key = :cache_key
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([':cache_key' => $this->cacheKey($tenantCode, $subtenantCode)]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string) $row['state_json'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function put(string $tenantCode, string $subtenantCode, array $state, int $ttlSeconds): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_tenant_access_cache
                (cache_key, tenant_code, subtenant_code, state_json, fetched_at, expires_at)
             VALUES
                (:cache_key, :tenant_code, :subtenant_code, :state_json, UTC_TIMESTAMP(), :expires_at)
             ON DUPLICATE KEY UPDATE
                state_json = VALUES(state_json),
                fetched_at = UTC_TIMESTAMP(),
                expires_at = VALUES(expires_at)'
        );
        $stmt->execute([
            ':cache_key' => $this->cacheKey($tenantCode, $subtenantCode),
            ':tenant_code' => $tenantCode,
            ':subtenant_code' => $subtenantCode,
            ':state_json' => json_encode($state, JSON_UNESCAPED_UNICODE),
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSeconds),
        ]);
    }

    private function cacheKey(string $tenantCode, string $subtenantCode): string
    {
        return strtolower(trim($tenantCode)) . '::' . strtolower(trim($subtenantCode));
    }
}
