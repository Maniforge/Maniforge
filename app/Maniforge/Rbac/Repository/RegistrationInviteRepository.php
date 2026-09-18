<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class RegistrationInviteRepository
{
    public function create(
        string $tenantId,
        string $subtenantName,
        string $roleCode,
        string $rawToken,
        string $expiresAt,
        ?int $createdBy = null,
        array $metadata = [],
        ?string $subtenantCode = null
    ): array {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_registration_invites (
                token_hash, tenant_id, subtenant_name, subtenant_code, role_code, expires_at, created_by, metadata_json
            ) VALUES (
                :token_hash, :tenant_id, :subtenant_name, :subtenant_code, :role_code, :expires_at, :created_by, :metadata_json
            )'
        );
        $normalizedSubtenantCode = $subtenantCode !== null && trim($subtenantCode) !== ''
            ? strtolower(trim($subtenantCode))
            : null;
        $stmt->execute([
            ':token_hash' => hash('sha256', $rawToken),
            ':tenant_id' => strtolower(trim($tenantId)),
            ':subtenant_name' => trim($subtenantName),
            ':subtenant_code' => $normalizedSubtenantCode,
            ':role_code' => trim($roleCode) !== '' ? trim($roleCode) : 'user',
            ':expires_at' => $expiresAt,
            ':created_by' => $createdBy,
            ':metadata_json' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int) Connection::get()->lastInsertId();

        return $this->findById($id) ?? [];
    }

    public function findPendingByToken(string $rawToken): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT *
             FROM maniforge_registration_invites
             WHERE token_hash = :token_hash
               AND status = :status
               AND consumed_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => hash('sha256', $rawToken),
            ':status' => 'pending',
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * Атомарно помечает invite consumed (FOR UPDATE). Второй параллельный вызов вернёт null.
     */
    public function isConsumedToken(string $rawToken): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT id
             FROM maniforge_registration_invites
             WHERE token_hash = :token_hash
               AND status = :status
               AND consumed_at IS NOT NULL
             LIMIT 1'
        );
        $stmt->execute([
            ':token_hash' => hash('sha256', $rawToken),
            ':status' => 'consumed',
        ]);

        return is_array($stmt->fetch());
    }

    public function claimPendingByToken(string $rawToken, string $subtenantCode): ?array
    {
        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT *
                 FROM maniforge_registration_invites
                 WHERE token_hash = :token_hash
                   AND status = :status
                   AND consumed_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([
                ':token_hash' => hash('sha256', $rawToken),
                ':status' => 'pending',
            ]);
            $row = $stmt->fetch();
            if (!is_array($row)) {
                $pdo->rollBack();

                return null;
            }

            $update = $pdo->prepare(
                'UPDATE maniforge_registration_invites
                 SET status = :consumed,
                     subtenant_code = :subtenant_code,
                     consumed_at = UTC_TIMESTAMP()
                 WHERE id = :id AND status = :pending'
            );
            $update->execute([
                ':consumed' => 'consumed',
                ':subtenant_code' => strtolower(trim($subtenantCode)),
                ':id' => (int) ($row['id'] ?? 0),
                ':pending' => 'pending',
            ]);
            if ($update->rowCount() === 0) {
                $pdo->rollBack();

                return null;
            }

            $pdo->commit();
            $row['status'] = 'consumed';
            $row['subtenant_code'] = strtolower(trim($subtenantCode));

            return $row;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public function markConsumed(int $inviteId, string $subtenantCode): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_registration_invites
             SET status = :status,
                 subtenant_code = :subtenant_code,
                 consumed_at = UTC_TIMESTAMP()
             WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            ':status' => 'consumed',
            ':subtenant_code' => strtolower(trim($subtenantCode)),
            ':id' => $inviteId,
            ':pending' => 'pending',
        ]);

        return $stmt->rowCount() > 0;
    }

    private function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_name, subtenant_code, status, role_code, expires_at, consumed_at, created_by, created_at
             FROM maniforge_registration_invites
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
