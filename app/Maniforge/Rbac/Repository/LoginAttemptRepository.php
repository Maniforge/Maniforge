<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class LoginAttemptRepository
{
    public function activeLock(string $tenantId, string $subtenantId, string $login, string $ip): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT failed_count, locked_until
             FROM maniforge_login_attempts
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND login = :login
               AND ip_hash = :ip_hash
               AND locked_until IS NOT NULL
               AND locked_until > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
            ':ip_hash' => hash('sha256', $ip),
        ]);

        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public function registerFailure(string $tenantId, string $subtenantId, string $login, string $ip): array
    {
        $maxFails = (int) ($_ENV['RBAC_LOGIN_MAX_FAILS'] ?? 5);
        $lockMinutes = (int) ($_ENV['RBAC_LOGIN_LOCK_MINUTES'] ?? 15);
        $ipHash = hash('sha256', $ip);

        $select = Connection::get()->prepare(
            'SELECT id, failed_count
             FROM maniforge_login_attempts
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND login = :login
               AND ip_hash = :ip_hash
             LIMIT 1'
        );
        $select->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
            ':ip_hash' => $ipHash,
        ]);
        $row = $select->fetch();

        if (is_array($row)) {
            $newCount = (int) $row['failed_count'] + 1;
            $lockedUntil = $newCount >= $maxFails ? gmdate('Y-m-d H:i:s', time() + ($lockMinutes * 60)) : null;
            $update = Connection::get()->prepare(
                'UPDATE maniforge_login_attempts
                 SET failed_count = :failed_count, last_failed_at = UTC_TIMESTAMP(), locked_until = :locked_until
                 WHERE id = :id'
            );
            $update->execute([
                ':failed_count' => $newCount,
                ':locked_until' => $lockedUntil,
                ':id' => (int) $row['id'],
            ]);

            return ['failed_count' => $newCount, 'locked_until' => $lockedUntil];
        }

        $initialLock = $maxFails <= 1 ? gmdate('Y-m-d H:i:s', time() + ($lockMinutes * 60)) : null;
        $insert = Connection::get()->prepare(
            'INSERT INTO maniforge_login_attempts (
                tenant_id, subtenant_id, login, ip_hash, failed_count, last_failed_at, locked_until, created_at
            ) VALUES (
                :tenant_id, :subtenant_id, :login, :ip_hash, 1, UTC_TIMESTAMP(), :locked_until, UTC_TIMESTAMP()
            )'
        );
        $insert->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
            ':ip_hash' => $ipHash,
            ':locked_until' => $initialLock,
        ]);

        return ['failed_count' => 1, 'locked_until' => $initialLock];
    }

    public function clear(string $tenantId, string $subtenantId, string $login, string $ip): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM maniforge_login_attempts
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND login = :login
               AND ip_hash = :ip_hash'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
            ':ip_hash' => hash('sha256', $ip),
        ]);
    }
}
