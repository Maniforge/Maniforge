<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Repository;

use App\Database\Connection;

final class VersioningRepository
{
    public function insertChange(array $row): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_ver_changes (
                tenant_id, subtenant_id, project_id, entity_table, entity_id, entity_label,
                operation, actor_user_id, correlation_id, before_json, after_json, changed_at
             ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :entity_table, :entity_id, :entity_label,
                :operation, :actor_user_id, :correlation_id, :before_json, :after_json, UTC_TIMESTAMP()
             )'
        );
        $stmt->execute([
            ':tenant_id' => (string) $row['tenant_id'],
            ':subtenant_id' => (string) ($row['subtenant_id'] ?? ''),
            ':project_id' => $row['project_id'] ?? null,
            ':entity_table' => (string) $row['entity_table'],
            ':entity_id' => (string) $row['entity_id'],
            ':entity_label' => $row['entity_label'] ?? null,
            ':operation' => (string) $row['operation'],
            ':actor_user_id' => $row['actor_user_id'] ?? null,
            ':correlation_id' => $row['correlation_id'] ?? null,
            ':before_json' => $this->encodeJson($row['before'] ?? null),
            ':after_json' => $this->encodeJson($row['after'] ?? null),
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function findByIdInScope(int $id, string $tenantId, string $subtenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_id, project_id, entity_table, entity_id, entity_label,
                    operation, actor_user_id, correlation_id, before_json, after_json, changed_at
             FROM maniforge_ver_changes
             WHERE id = :id AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             LIMIT 1'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listInScope(string $tenantId, string $subtenantId, array $filters): array
    {
        $where = ['tenant_id = :tenant_id', 'subtenant_id = :subtenant_id'];
        $params = [
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ];

        if (($filters['entity_table'] ?? '') !== '') {
            $where[] = 'entity_table = :entity_table';
            $params[':entity_table'] = (string) $filters['entity_table'];
        }
        if (($filters['entity_id'] ?? '') !== '') {
            $where[] = 'entity_id = :entity_id';
            $params[':entity_id'] = (string) $filters['entity_id'];
        }
        if (($filters['operation'] ?? '') !== '') {
            $where[] = 'operation = :operation';
            $params[':operation'] = (string) $filters['operation'];
        }
        if (($filters['project_id'] ?? null) !== null && (int) $filters['project_id'] > 0) {
            $where[] = 'project_id = :project_id';
            $params[':project_id'] = (int) $filters['project_id'];
        }
        if (($filters['from'] ?? '') !== '') {
            $where[] = 'changed_at >= :from_ts';
            $params[':from_ts'] = (string) $filters['from'];
        }
        if (($filters['to'] ?? '') !== '') {
            $where[] = 'changed_at <= :to_ts';
            $params[':to_ts'] = (string) $filters['to'];
        }

        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = 'SELECT id, tenant_id, subtenant_id, project_id, entity_table, entity_id, entity_label,
                       operation, actor_user_id, correlation_id, before_json, after_json, changed_at
                FROM maniforge_ver_changes
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY changed_at DESC, id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = $this->mapRow($row);
            }
        }

        return $items;
    }

    public function countInScope(string $tenantId, string $subtenantId, array $filters): int
    {
        $where = ['tenant_id = :tenant_id', 'subtenant_id = :subtenant_id'];
        $params = [
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ];

        if (($filters['entity_table'] ?? '') !== '') {
            $where[] = 'entity_table = :entity_table';
            $params[':entity_table'] = (string) $filters['entity_table'];
        }
        if (($filters['entity_id'] ?? '') !== '') {
            $where[] = 'entity_id = :entity_id';
            $params[':entity_id'] = (string) $filters['entity_id'];
        }
        if (($filters['operation'] ?? '') !== '') {
            $where[] = 'operation = :operation';
            $params[':operation'] = (string) $filters['operation'];
        }

        $sql = 'SELECT COUNT(*) FROM maniforge_ver_changes WHERE ' . implode(' AND ', $where);
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRegistry(bool $activeOnly = true): array
    {
        $sql = 'SELECT id, entity_table, entity_label, description, is_active, created_at
                FROM maniforge_ver_registry';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY entity_label ASC';

        $items = [];
        foreach (Connection::get()->query($sql)->fetchAll() as $row) {
            if (is_array($row)) {
                $items[] = [
                    'id' => (int) $row['id'],
                    'entity_table' => (string) $row['entity_table'],
                    'entity_label' => (string) $row['entity_label'],
                    'description' => $row['description'] !== null ? (string) $row['description'] : null,
                    'is_active' => (int) $row['is_active'] === 1,
                    'created_at' => $row['created_at'] ?? null,
                ];
            }
        }

        return $items;
    }

    public function isTableTracked(string $entityTable): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT 1 FROM maniforge_ver_registry WHERE entity_table = :entity_table AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':entity_table' => $entityTable]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (string) $row['tenant_id'],
            'subtenant_id' => (string) $row['subtenant_id'],
            'project_id' => $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'entity_table' => (string) $row['entity_table'],
            'entity_id' => (string) $row['entity_id'],
            'entity_label' => $row['entity_label'] !== null ? (string) $row['entity_label'] : null,
            'operation' => (string) $row['operation'],
            'actor_user_id' => $row['actor_user_id'] !== null ? (int) $row['actor_user_id'] : null,
            'correlation_id' => $row['correlation_id'] !== null ? (string) $row['correlation_id'] : null,
            'before' => $this->decodeJson($row['before_json'] ?? null),
            'after' => $this->decodeJson($row['after_json'] ?? null),
            'changed_at' => $row['changed_at'] ?? null,
        ];
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
