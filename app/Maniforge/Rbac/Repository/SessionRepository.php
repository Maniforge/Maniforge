<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class SessionRepository
{
    public function create(array $data): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_sessions (
                id, user_id, tenant_id, subtenant_id, project_id, session_secret_hash, ip_hash, user_agent_hash,
                aal, last_activity_at, expires_at, security_version_snapshot, created_at
            ) VALUES (
                :id, :user_id, :tenant_id, :subtenant_id, :project_id, :session_secret_hash, :ip_hash, :user_agent_hash,
                :aal, :last_activity_at, :expires_at, :security_version_snapshot, :created_at
            )'
        );

        $stmt->execute($data);
    }

    public function findActiveByToken(string $token): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_sessions
             WHERE session_secret_hash = :hash
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([':hash' => hash('sha256', $token)]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function findById(string $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_sessions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function touch(string $id): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions SET last_activity_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function rebindScope(string $id, string $tenantId, string $subtenantId): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions
             SET tenant_id = :tenant_id, subtenant_id = :subtenant_id, project_id = NULL, last_activity_at = UTC_TIMESTAMP()
             WHERE id = :id AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function rebindProject(string $id, ?int $projectId): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions
             SET project_id = :project_id, last_activity_at = UTC_TIMESTAMP()
             WHERE id = :id AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':id' => $id,
            ':project_id' => $projectId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function markMfaVerified(string $id): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions SET mfa_verified_at = UTC_TIMESTAMP() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function revoke(string $id, string $reason): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':id' => $id,
            ':reason' => $reason,
        ]);
    }

    public function existsInScope(string $sessionId, string $tenantId, string $subtenantId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT id
             FROM maniforge_sessions
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return is_array($stmt->fetch());
    }

    public function revokeInScope(string $id, string $tenantId, string $subtenantId, string $reason): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':reason' => $reason,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function existsActiveInScope(string $sessionId, string $tenantId, string $subtenantId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT id
             FROM maniforge_sessions
             WHERE id = :id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $sessionId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return is_array($stmt->fetch());
    }

    public function revokeBatchInScope(array $sessionIds, string $tenantId, string $subtenantId, string $reason): int
    {
        if ($sessionIds === []) {
            return 0;
        }

        $pdo = Connection::get();
        $revoked = 0;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'UPDATE maniforge_sessions
                 SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
                 WHERE id = :id
                   AND tenant_id = :tenant_id
                   AND subtenant_id = :subtenant_id
                   AND revoked_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()'
            );
            foreach ($sessionIds as $sessionId) {
                $stmt->execute([
                    ':reason' => $reason,
                    ':id' => (string) $sessionId,
                    ':tenant_id' => $tenantId,
                    ':subtenant_id' => $subtenantId,
                ]);
                if ($stmt->rowCount() > 0) {
                    $revoked++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $revoked;
    }

    public function countActiveInScope(string $tenantId, string $subtenantId): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) AS total
             FROM maniforge_sessions
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND revoked_at IS NULL
               AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function listByScope(string $tenantId, string $subtenantId, int $limit = 100): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, user_id, tenant_id, subtenant_id, project_id, aal, mfa_verified_at, last_activity_at, expires_at, revoked_at, revoke_reason
             FROM maniforge_sessions
             WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             ORDER BY created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':subtenant_id', $subtenantId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function revokeAllUserSessions(int $userId, string $reason): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_sessions
             SET revoked_at = UTC_TIMESTAMP(), revoke_reason = :reason
             WHERE user_id = :user_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':reason' => $reason,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @param list<array{user_id: int, tenant_id: string, subtenant_id: string}> $scopes
     * @return array<string, string> scope key (user_id|tenant_id|subtenant_id) => last login UTC datetime
     */
    public function findLastLoginAtByUserScopes(array $scopes): array
    {
        if ($scopes === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($scopes as $index => $scope) {
            $conditions[] = "(user_id = :uid{$index} AND tenant_id = :tid{$index} AND subtenant_id = :sid{$index})";
            $params[":uid{$index}"] = (int) ($scope['user_id'] ?? 0);
            $params[":tid{$index}"] = (string) ($scope['tenant_id'] ?? '');
            $params[":sid{$index}"] = (string) ($scope['subtenant_id'] ?? '');
        }

        $stmt = Connection::get()->prepare(
            'SELECT user_id, tenant_id, subtenant_id,
                    MAX(GREATEST(created_at, last_activity_at)) AS last_login_at
             FROM maniforge_sessions
             WHERE ' . implode(' OR ', $conditions) . '
             GROUP BY user_id, tenant_id, subtenant_id'
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = self::userScopeKey(
                (int) ($row['user_id'] ?? 0),
                (string) ($row['tenant_id'] ?? ''),
                (string) ($row['subtenant_id'] ?? '')
            );
            $lastLoginAt = (string) ($row['last_login_at'] ?? '');
            if ($key !== '' && $lastLoginAt !== '') {
                $result[$key] = $lastLoginAt;
            }
        }

        return $result;
    }

    public static function userScopeKey(int $userId, string $tenantId, string $subtenantId): string
    {
        if ($userId <= 0 || $tenantId === '' || $subtenantId === '') {
            return '';
        }

        return $userId . '|' . $tenantId . '|' . $subtenantId;
    }

    public function revokeAllInTenant(string $tenantId, ?string $subtenantId, string $reason): int
    {
        $sql = 'UPDATE maniforge_sessions
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
