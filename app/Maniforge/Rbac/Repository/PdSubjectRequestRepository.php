<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class PdSubjectRequestRepository
{
    public const TYPES = ['access', 'rectification', 'erasure', 'restriction', 'withdraw_consent'];
    public const STATUSES = ['pending', 'in_progress', 'completed', 'rejected'];

    public function create(
        int $userId,
        string $tenantId,
        string $subtenantId,
        string $requestType,
        ?array $payload,
        ?string $dueAt
    ): array {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_pd_subject_requests (
                user_id, tenant_id, subtenant_id, request_type, status, payload_json, due_at
            ) VALUES (
                :user_id, :tenant_id, :subtenant_id, :request_type, :status, :payload_json, :due_at
            )'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':request_type' => $requestType,
            ':status' => 'pending',
            ':payload_json' => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':due_at' => $dueAt,
        ]);

        $id = (int) Connection::get()->lastInsertId();

        return $this->findById($id) ?? [];
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare('SELECT * FROM maniforge_pd_subject_requests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->normalize($row) : null;
    }

    public function listForUser(int $userId, string $tenantId, string $subtenantId, int $limit = 50): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_pd_subject_requests
             WHERE user_id = :user_id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             ORDER BY created_at DESC LIMIT :lim'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':subtenant_id', $subtenantId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $r): array => $this->normalize($r), $rows);
    }

    public function listForScope(string $tenantId, string $subtenantId, ?string $status, int $limit = 100): array
    {
        $sql = 'SELECT * FROM maniforge_pd_subject_requests
                WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id';
        $params = [':tenant_id' => $tenantId, ':subtenant_id' => $subtenantId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :lim';

        $stmt = Connection::get()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];

        return array_map(fn (array $r): array => $this->normalize($r), $rows);
    }

    public function resolve(
        int $id,
        string $tenantId,
        string $subtenantId,
        string $status,
        int $handlerUserId,
        ?string $handlerNote
    ): ?array {
        if (!in_array($status, ['completed', 'rejected', 'in_progress'], true)) {
            return null;
        }

        $completedAt = in_array($status, ['completed', 'rejected'], true) ? 'UTC_TIMESTAMP()' : 'NULL';
        $stmt = Connection::get()->prepare(
            "UPDATE maniforge_pd_subject_requests
             SET status = :status,
                 handler_user_id = :handler_user_id,
                 handler_note = :handler_note,
                 completed_at = {$completedAt}
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id"
        );
        $stmt->execute([
            ':status' => $status,
            ':handler_user_id' => $handlerUserId,
            ':handler_note' => $handlerNote,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        if ($stmt->rowCount() === 0) {
            return null;
        }

        return $this->findById($id);
    }

    private function normalize(array $row): array
    {
        $payload = $row['payload_json'] ?? null;
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }
        unset($row['payload_json']);

        return $row;
    }
}
