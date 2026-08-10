<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PdConsentRepository
{
    public function grant(
        int $userId,
        string $tenantId,
        string $subtenantId,
        string $purposeCode,
        string $policyVersion,
        string $source,
        ?string $ipHash,
        ?string $userAgentHash
    ): array {
        $this->revokeActive($userId, $tenantId, $subtenantId, $purposeCode);

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_pd_consents (
                user_id, tenant_id, subtenant_id, purpose_code, policy_version,
                granted_at, source, ip_hash, user_agent_hash
            ) VALUES (
                :user_id, :tenant_id, :subtenant_id, :purpose_code, :policy_version,
                UTC_TIMESTAMP(), :source, :ip_hash, :user_agent_hash
            )'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':purpose_code' => $purposeCode,
            ':policy_version' => $policyVersion,
            ':source' => $source,
            ':ip_hash' => $ipHash,
            ':user_agent_hash' => $userAgentHash,
        ]);

        return $this->findLatest($userId, $tenantId, $subtenantId, $purposeCode) ?? [];
    }

    public function revokeActive(int $userId, string $tenantId, string $subtenantId, string $purposeCode): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_pd_consents
             SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND purpose_code = :purpose_code
               AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':purpose_code' => $purposeCode,
        ]);
    }

    public function listForUser(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, purpose_code, policy_version, granted_at, revoked_at, source
             FROM maniforge_pd_consents
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             ORDER BY granted_at DESC, id DESC
             LIMIT 200'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return $stmt->fetchAll() ?: [];
    }

    public function hasActiveConsent(int $userId, string $tenantId, string $subtenantId, string $purposeCode): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM maniforge_pd_consents
             WHERE user_id = :user_id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND purpose_code = :purpose_code
               AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':purpose_code' => $purposeCode,
        ]);

        return (bool) $stmt->fetch();
    }

    private function findLatest(int $userId, string $tenantId, string $subtenantId, string $purposeCode): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_pd_consents
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
               AND purpose_code = :purpose_code
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':purpose_code' => $purposeCode,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
