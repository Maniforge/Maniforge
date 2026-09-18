<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Security\PiiFieldCodec;

final class UserRepository
{
    private const STATUS_ACTIVE = 'active';
    private const STATUS_LOCKED = 'locked';
    private const STATUS_DISABLED = 'disabled';

    public function __construct(
        private readonly PiiFieldCodec $pii = new PiiFieldCodec(),
    ) {
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByIdForSession(array $session): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_id, login, email, phone, email_enc, phone_enc, pii_enc_version,
                    status, mfa_required, security_version, password_hash, created_at, updated_at
             FROM maniforge_users
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => (int) $session['user_id'],
            ':tenant_id' => (string) $session['tenant_id'],
            ':subtenant_id' => (string) $session['subtenant_id'],
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByLogin(string $tenantId, string $subtenantId, string $login): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_users WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id AND login = :login LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByPhone(string $phone): array
    {
        $lookup = $this->pii->phoneLookupValueGlobal($phone);
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_users WHERE phone = :phone ORDER BY updated_at DESC, id DESC'
        );
        $stmt->execute([':phone' => $lookup]);
        $rows = $stmt->fetchAll();

        if (!is_array($rows)) {
            return [];
        }

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    public function findByPhoneInScope(string $phone, string $tenantId, string $subtenantId): ?array
    {
        $lookup = $this->pii->phoneLookupValue($phone, $tenantId, $subtenantId);
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_users
             WHERE phone = :phone AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':phone' => $lookup,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByIdInScope(int $id, string $tenantId, string $subtenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_id, login, email, phone, email_enc, phone_enc, pii_enc_version,
                    status, mfa_required, security_version, created_at, updated_at
             FROM maniforge_users
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> id => user row
     */
    public function findManyByIdsInScope(array $ids, string $tenantId, string $subtenantId): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn ($id): int => (int) $id, $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Connection::get()->prepare(
            "SELECT id, tenant_id, subtenant_id, login, email, phone, email_enc, phone_enc, pii_enc_version,
                    status, mfa_required, security_version, created_at, updated_at
             FROM maniforge_users
             WHERE tenant_id = ? AND subtenant_id = ? AND id IN ({$placeholders})"
        );
        $params = [$tenantId, $subtenantId, ...$ids];
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $hydrated = $this->hydrate($row);
            $result[(int) ($hydrated['id'] ?? 0)] = $hydrated;
        }

        return $result;
    }

    public function listUsers(string $tenantId, string $subtenantId, int $limit = 50): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, login, email, phone, email_enc, phone_enc, pii_enc_version,
                    status, mfa_required, security_version, created_at, updated_at
             FROM maniforge_users
             WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':subtenant_id', $subtenantId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    public function countActiveUsers(string $tenantId, string $subtenantId): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) AS total
             FROM maniforge_users
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND status = :status'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':status' => self::STATUS_ACTIVE,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function createUser(
        string $tenantId,
        string $subtenantId,
        string $login,
        ?string $email,
        string $phone,
        string $passwordHash,
        bool $mfaRequired,
        string $status
    ): array {
        $packed = $this->pii->packForStorage($email, $phone, $tenantId, $subtenantId);

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_users (
                tenant_id, subtenant_id, login, email, email_enc, phone, phone_enc, pii_enc_version,
                password_hash, mfa_required, security_version, status, last_password_changed_at
            ) VALUES (
                :tenant_id, :subtenant_id, :login, :email, :email_enc, :phone, :phone_enc, :pii_enc_version,
                :password_hash, :mfa_required, 1, :status, UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':login' => $login,
            ':email' => $packed['email'],
            ':email_enc' => $packed['email_enc'],
            ':phone' => $packed['phone'],
            ':phone_enc' => $packed['phone_enc'],
            ':pii_enc_version' => $packed['pii_enc_version'],
            ':password_hash' => $passwordHash,
            ':mfa_required' => $mfaRequired ? 1 : 0,
            ':status' => $status,
        ]);

        return $this->findByIdInScope((int) Connection::get()->lastInsertId(), $tenantId, $subtenantId) ?? [];
    }

    public function updateUserInScope(int $userId, string $tenantId, string $subtenantId, array $changes): ?array
    {
        $allowed = ['login', 'email', 'phone', 'mfa_required', 'status', 'password_hash'];
        $sets = [];
        $params = [
            ':id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ];

        if (array_key_exists('email', $changes) || array_key_exists('phone', $changes)) {
            $current = $this->findByIdInScope($userId, $tenantId, $subtenantId);
            $email = array_key_exists('email', $changes)
                ? ($changes['email'] === null ? null : (string) $changes['email'])
                : ($current['email'] ?? null);
            $phone = array_key_exists('phone', $changes)
                ? (string) $changes['phone']
                : (string) ($current['phone'] ?? '');
            $packed = $this->pii->packForStorage($email, $phone, $tenantId, $subtenantId);
            $sets[] = 'email = :email';
            $sets[] = 'email_enc = :email_enc';
            $sets[] = 'phone = :phone';
            $sets[] = 'phone_enc = :phone_enc';
            $sets[] = 'pii_enc_version = :pii_enc_version';
            $params[':email'] = $packed['email'];
            $params[':email_enc'] = $packed['email_enc'];
            $params[':phone'] = $packed['phone'];
            $params[':phone_enc'] = $packed['phone_enc'];
            $params[':pii_enc_version'] = $packed['pii_enc_version'];
            unset($changes['email'], $changes['phone']);
        }

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $sets[] = "{$field} = :{$field}";
            $params[":{$field}"] = $field === 'mfa_required'
                ? (filter_var($changes[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0)
                : $changes[$field];
        }

        if (array_key_exists('password_hash', $changes)) {
            $sets[] = 'last_password_changed_at = UTC_TIMESTAMP()';
            $sets[] = 'security_version = security_version + 1';
        }

        if ($sets === []) {
            return $this->findByIdInScope($userId, $tenantId, $subtenantId);
        }

        $sets[] = 'updated_at = UTC_TIMESTAMP()';
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_users SET ' . implode(', ', $sets) . '
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id'
        );
        $stmt->execute($params);

        return $this->findByIdInScope($userId, $tenantId, $subtenantId);
    }

    public function deleteUserInScope(int $userId, string $tenantId, string $subtenantId): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_users
             SET status = :status,
                 security_version = security_version + 1,
                 updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
               AND status <> :status'
        );
        $stmt->execute([
            ':id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':status' => 'deleted',
        ]);

        return $stmt->rowCount() > 0;
    }

    public function findStatusInScope(int $userId, string $tenantId, string $subtenantId): ?string
    {
        $stmt = Connection::get()->prepare(
            'SELECT status
             FROM maniforge_users
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return (string) ($row['status'] ?? '');
    }

    public function applyStatusBatchInScope(string $tenantId, string $subtenantId, array $items): array
    {
        $pdo = Connection::get();
        $changed = 0;
        $skipped = 0;
        $notFound = 0;

        try {
            $pdo->beginTransaction();
            $select = $pdo->prepare(
                'SELECT status
                 FROM maniforge_users
                 WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
                 LIMIT 1'
            );
            $update = $pdo->prepare(
                'UPDATE maniforge_users
                 SET status = :status, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id'
            );

            foreach ($items as $item) {
                $userId = (int) ($item['user_id'] ?? 0);
                $status = (string) ($item['status'] ?? '');

                $select->execute([
                    ':id' => $userId,
                    ':tenant_id' => $tenantId,
                    ':subtenant_id' => $subtenantId,
                ]);
                $row = $select->fetch();
                if (!is_array($row)) {
                    $notFound++;
                    continue;
                }

                $current = (string) ($row['status'] ?? '');
                if ($current === $status) {
                    $skipped++;
                    continue;
                }

                $update->execute([
                    ':status' => $status,
                    ':id' => $userId,
                    ':tenant_id' => $tenantId,
                    ':subtenant_id' => $subtenantId,
                ]);
                if ($update->rowCount() > 0) {
                    $changed++;
                    continue;
                }

                $skipped++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'changed' => $changed,
            'skipped' => $skipped,
            'not_found' => $notFound,
            'total' => count($items),
            'by_status' => $this->buildStatusBreakdown($items),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlaintextPiiBatch(int $afterId, int $limit): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_id, email, phone, pii_enc_version
             FROM maniforge_users
             WHERE pii_enc_version = 0 AND id > :after_id
             ORDER BY id ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':after_id', $afterId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    public function upgradeRowToEncrypted(int $userId, ?string $email, string $phone, string $tenantId, string $subtenantId): void
    {
        $packed = $this->pii->packForStorage($email, $phone, $tenantId, $subtenantId);
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_users
             SET email = :email, email_enc = :email_enc, phone = :phone, phone_enc = :phone_enc,
                 pii_enc_version = :pii_enc_version, updated_at = UTC_TIMESTAMP()
             WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $userId,
            ':email' => $packed['email'],
            ':email_enc' => $packed['email_enc'],
            ':phone' => $packed['phone'],
            ':phone_enc' => $packed['phone_enc'],
            ':pii_enc_version' => $packed['pii_enc_version'],
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        return $this->pii->hydrateRow($row);
    }

    private function buildStatusBreakdown(array $items): array
    {
        $byStatus = [
            self::STATUS_ACTIVE => 0,
            self::STATUS_LOCKED => 0,
            self::STATUS_DISABLED => 0,
        ];

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            if (isset($byStatus[$status])) {
                $byStatus[$status]++;
            }
        }

        return $byStatus;
    }
}
