<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class RefreshTokenRepository
{
    public function create(array $data): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_refresh_tokens (
                id, session_id, user_id, tenant_id, subtenant_id, project_id, token_hash, expires_at, created_at
            ) VALUES (
                :id, :session_id, :user_id, :tenant_id, :subtenant_id, :project_id, :token_hash, :expires_at, :created_at
            )'
        );
        $stmt->execute($data);
    }

    public function findActiveByToken(string $token): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_refresh_tokens
             WHERE token_hash = :token_hash
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function revokeById(string $id, string $reason): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_refresh_tokens
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([':id' => $id, ':reason' => $reason]);
    }

    public function revokeAllForUser(int $userId, string $reason): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_refresh_tokens
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId, ':reason' => $reason]);
        return $stmt->rowCount();
    }

    public function revokeBySessionId(string $sessionId, string $reason): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_refresh_tokens
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE session_id = :session_id AND revoked_at IS NULL'
        );
        $stmt->execute([':session_id' => $sessionId, ':reason' => $reason]);

        return $stmt->rowCount();
    }

    public function revokeBySessionIdsInScope(array $sessionIds, string $tenantId, string $subtenantId, string $reason): int
    {
        if ($sessionIds === []) {
            return 0;
        }

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_refresh_tokens
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE session_id = :session_id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND revoked_at IS NULL'
        );
        $revoked = 0;
        foreach ($sessionIds as $sessionId) {
            $stmt->execute([
                ':session_id' => (string) $sessionId,
                ':tenant_id' => $tenantId,
                ':subtenant_id' => $subtenantId,
                ':reason' => $reason,
            ]);
            $revoked += $stmt->rowCount();
        }

        return $revoked;
    }

    public function revokeAllInTenant(string $tenantId, ?string $subtenantId, string $reason): int
    {
        $sql = 'UPDATE maniforge_refresh_tokens
                SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
                WHERE tenant_id = :tenant_id
                  AND revoked_at IS NULL';
        $params = [
            ':tenant_id' => $tenantId,
            ':reason' => $reason,
        ];
        if ($subtenantId !== null && $subtenantId !== '') {
            $sql .= ' AND subtenant_id = :subtenant_id';
            $params[':subtenant_id'] = $subtenantId;
        }

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }
}
