<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class ActionTokenRepository
{
    public function create(array $data): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_action_tokens (
                id, session_id, user_id, tenant_id, subtenant_id, token_hash, purpose, expires_at, created_at
            ) VALUES (
                :id, :session_id, :user_id, :tenant_id, :subtenant_id, :token_hash, :purpose, :expires_at, :created_at
            )'
        );
        $stmt->execute($data);
    }

    public function findActiveByToken(string $token): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT *
             FROM maniforge_action_tokens
             WHERE token_hash = :hash
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function revokeActiveForSession(string $sessionId): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_action_tokens
             SET revoked_at = UTC_TIMESTAMP()
             WHERE session_id = :session_id AND revoked_at IS NULL'
        );
        $stmt->execute([':session_id' => $sessionId]);

        return $stmt->rowCount();
    }

    public function revokeAllForUser(int $userId): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_action_tokens
             SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([':user_id' => $userId]);

        return $stmt->rowCount();
    }
}
