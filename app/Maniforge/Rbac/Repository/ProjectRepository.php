<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class ProjectRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listInScope(string $tenantId, string $subtenantId, bool $includeTenantLevel): array
    {
        $sql = 'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                       p.warehouse_id, p.created_at, p.updated_at,
                       w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
                FROM maniforge_projects p
                LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
                WHERE p.tenant_id = :tenant_id AND p.status = :status';
        $params = [
            ':tenant_id' => $tenantId,
            ':status' => 'active',
        ];

        if ($includeTenantLevel) {
            $sql .= ' AND (p.subtenant_id = :subtenant_id OR p.subtenant_id = :tenant_level)';
            $params[':subtenant_id'] = $subtenantId;
            $params[':tenant_level'] = '';
        } else {
            $sql .= ' AND p.subtenant_id = :subtenant_id';
            $params[':subtenant_id'] = $subtenantId;
        }

        $sql .= ' ORDER BY p.subtenant_id ASC, p.code ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $this->mapRows($stmt->fetchAll());
    }

    public function findByCodeInScope(string $tenantId, string $subtenantId, string $code, bool $includeTenantLevel): ?array
    {
        $code = strtolower(trim($code));
        $sql = 'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                       p.warehouse_id, p.created_at, p.updated_at,
                       w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
                FROM maniforge_projects p
                LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
                WHERE p.tenant_id = :tenant_id AND p.code = :code AND p.status = :status';
        $params = [
            ':tenant_id' => $tenantId,
            ':code' => $code,
            ':status' => 'active',
        ];

        if ($includeTenantLevel) {
            $sql .= ' AND (p.subtenant_id = :subtenant_id OR p.subtenant_id = :tenant_level)';
            $params[':subtenant_id'] = $subtenantId;
            $params[':tenant_level'] = '';
        } else {
            $sql .= ' AND p.subtenant_id = :subtenant_id';
            $params[':subtenant_id'] = $subtenantId;
        }

        $sql .= ' LIMIT 1';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                    p.warehouse_id, p.created_at, p.updated_at,
                    w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
             FROM maniforge_projects p
             LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function findDefaultByTenant(string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                    p.warehouse_id, p.created_at, p.updated_at,
                    w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
             FROM maniforge_projects p
             LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
             WHERE p.tenant_id = :tenant_id AND p.subtenant_id = :tenant_level
               AND p.is_default = 1 AND p.status = :status
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':tenant_level' => '',
            ':status' => 'active',
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findDefaultBySubtenant(string $tenantId, string $subtenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                    p.warehouse_id, p.created_at, p.updated_at,
                    w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
             FROM maniforge_projects p
             LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
             WHERE p.tenant_id = :tenant_id AND p.subtenant_id = :subtenant_id
               AND p.is_default = 1 AND p.status = :status
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':status' => 'active',
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function markAsDefault(int $projectId): void
    {
        $project = $this->findById($projectId);
        if ($project === null) {
            return;
        }
        $tenantId = (string) $project['tenant_id'];
        $subtenantId = (string) $project['subtenant_id'];

        $pdo = Connection::get();
        $pdo->prepare(
            'UPDATE maniforge_projects SET is_default = 0
             WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id'
        )->execute([':tenant_id' => $tenantId, ':subtenant_id' => $subtenantId]);
        $pdo->prepare('UPDATE maniforge_projects SET is_default = 1 WHERE id = :id')
            ->execute([':id' => $projectId]);
    }

    public function create(
        string $tenantId,
        string $subtenantId,
        string $code,
        string $name,
        ?array $metadata = null,
        ?int $warehouseId = null,
        bool $isDefault = false,
    ): array {
        if ($isDefault) {
            Connection::get()->prepare(
                'UPDATE maniforge_projects SET is_default = 0
                 WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id'
            )->execute([':tenant_id' => $tenantId, ':subtenant_id' => $subtenantId]);
        }

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_projects (tenant_id, subtenant_id, code, name, status, is_default, metadata_json, warehouse_id)
             VALUES (:tenant_id, :subtenant_id, :code, :name, :status, :is_default, :metadata_json, :warehouse_id)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':code' => $code,
            ':name' => $name,
            ':status' => 'active',
            ':is_default' => $isDefault ? 1 : 0,
            ':metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ':warehouse_id' => $warehouseId,
        ]);

        $id = (int) Connection::get()->lastInsertId();
        $project = $this->findById($id);

        return $project ?? [
            'id' => $id,
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantId,
            'code' => $code,
            'name' => $name,
            'status' => 'active',
        ];
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function updateById(int $id, array $changes): ?array
    {
        $sets = [];
        $params = [':id' => $id];
        foreach (['name', 'status'] as $field) {
            if (!array_key_exists($field, $changes)) {
                continue;
            }
            $sets[] = "{$field} = :{$field}";
            $params[":{$field}"] = $changes[$field];
        }
        if (array_key_exists('metadata', $changes)) {
            $sets[] = 'metadata_json = :metadata_json';
            $meta = $changes['metadata'];
            $params[':metadata_json'] = $meta === null ? null : json_encode($meta, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('warehouse_id', $changes)) {
            $sets[] = 'warehouse_id = :warehouse_id';
            $params[':warehouse_id'] = $changes['warehouse_id'];
        }
        if ($sets === []) {
            return $this->findById($id);
        }

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_projects SET ' . implode(', ', $sets) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        return $this->findById($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.code, p.name, p.status, p.is_default, p.metadata_json,
                    p.warehouse_id, p.created_at, p.updated_at,
                    w.code AS warehouse_code, w.name AS warehouse_name, w.type AS warehouse_type
             FROM maniforge_user_project_memberships m
             INNER JOIN maniforge_projects p ON p.id = m.project_id AND p.status = :status
             LEFT JOIN maniforge_wh_stocks w ON w.id = p.warehouse_id
             WHERE m.user_id = :user_id
               AND m.tenant_id = :tenant_id
               AND m.subtenant_id = :subtenant_id
             ORDER BY p.code ASC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':status' => 'active',
        ]);

        return $this->mapRows($stmt->fetchAll());
    }

    public function assignUser(int $userId, int $projectId, string $tenantId, string $subtenantId): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT IGNORE INTO maniforge_user_project_memberships (user_id, project_id, tenant_id, subtenant_id)
             VALUES (:user_id, :project_id, :tenant_id, :subtenant_id)'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':project_id' => $projectId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
    }

    public function userHasMembership(int $userId, int $projectId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT id FROM maniforge_user_project_memberships
             WHERE user_id = :user_id AND project_id = :project_id LIMIT 1'
        );
        $stmt->execute([':user_id' => $userId, ':project_id' => $projectId]);

        return is_array($stmt->fetch());
    }

    /**
     * @param list<array> $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->mapRow($row);
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $meta = $row['metadata_json'] ?? null;
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $row['metadata'] = is_array($decoded) ? $decoded : null;
        } else {
            $row['metadata'] = null;
        }
        unset($row['metadata_json']);
        $row['id'] = (int) $row['id'];
        $row['is_default'] = (bool) ($row['is_default'] ?? false);
        $row['subtenant_id'] = (string) $row['subtenant_id'];
        if ($row['subtenant_id'] === '') {
            $row['scope'] = 'tenant';
            $row['project_scope'] = 'tenant';
        } else {
            $row['scope'] = 'subtenant';
            $row['project_scope'] = 'subtenant';
        }

        $warehouseId = $row['warehouse_id'] ?? null;
        if ($warehouseId !== null && $warehouseId !== '') {
            $row['warehouse_id'] = (int) $warehouseId;
            $row['warehouse'] = [
                'id' => (int) $warehouseId,
                'code' => (string) ($row['warehouse_code'] ?? ''),
                'name' => (string) ($row['warehouse_name'] ?? ''),
                'type' => (string) ($row['warehouse_type'] ?? ''),
            ];
        } else {
            $row['warehouse_id'] = null;
            $row['warehouse'] = null;
        }
        unset($row['warehouse_code'], $row['warehouse_name'], $row['warehouse_type']);

        return $row;
    }
}
