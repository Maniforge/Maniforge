<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PasswordHistoryRepository
{
    public function add(int $userId, string $passwordHash): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_password_history (user_id, password_hash, created_at)
             VALUES (:user_id, :password_hash, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':password_hash' => $passwordHash,
        ]);
    }

    public function recentHashes(int $userId, int $limit = 5): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT password_hash
             FROM maniforge_password_history
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row): string => (string) $row['password_hash'], $stmt->fetchAll());
    }
}
