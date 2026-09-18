<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class ScopeVariableRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listVisible(
        string $tenantId,
        string $subtenantId,
        ?int $projectId
    ): array {
        if ($projectId !== null && $projectId > 0) {
            $sql = 'SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, created_at, updated_at
                    FROM maniforge_scope_variables
                    WHERE tenant_id = :tenant_id
                      AND (
                        (scope_level = :tenant_level AND subtenant_id = :empty AND project_id IS NULL)
                        OR (scope_level = :subtenant_level AND subtenant_id = :subtenant_id AND project_id IS NULL)
                        OR (scope_level = :project_level AND project_id = :project_id)
                      )
                    ORDER BY scope_level ASC, var_key ASC';
            $params = [
                ':tenant_id' => $tenantId,
                ':empty' => '',
                ':subtenant_id' => $subtenantId,
                ':tenant_level' => 'tenant',
                ':subtenant_level' => 'subtenant',
                ':project_level' => 'project',
                ':project_id' => $projectId,
            ];
        } else {
            $sql = 'SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, created_at, updated_at
                    FROM maniforge_scope_variables
                    WHERE tenant_id = :tenant_id
                      AND (
                        (scope_level = :tenant_level AND subtenant_id = :empty AND project_id IS NULL)
                        OR (scope_level = :subtenant_level AND subtenant_id = :subtenant_id AND project_id IS NULL)
                      )
                    ORDER BY scope_level ASC, var_key ASC';
            $params = [
                ':tenant_id' => $tenantId,
                ':empty' => '',
                ':subtenant_id' => $subtenantId,
                ':tenant_level' => 'tenant',
                ':subtenant_level' => 'subtenant',
            ];
        }

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

    public function findByKey(
        string $tenantId,
        string $subtenantId,
        ?int $projectId,
        string $varKey
    ): ?array {
        if ($projectId === null) {
            $stmt = Connection::get()->prepare(
                'SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, created_at, updated_at
                 FROM maniforge_scope_variables
                 WHERE tenant_id = :tenant_id
                   AND subtenant_id = :subtenant_id
                   AND project_id IS NULL
                   AND var_key = :var_key
                 LIMIT 1'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':subtenant_id' => $subtenantId,
                ':var_key' => $varKey,
            ]);
        } else {
            $stmt = Connection::get()->prepare(
                'SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, created_at, updated_at
                 FROM maniforge_scope_variables
                 WHERE tenant_id = :tenant_id
                   AND subtenant_id = :subtenant_id
                   AND project_id = :project_id
                   AND var_key = :var_key
                 LIMIT 1'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':subtenant_id' => $subtenantId,
                ':project_id' => $projectId,
                ':var_key' => $varKey,
            ]);
        }
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function upsert(
        string $tenantId,
        string $subtenantId,
        ?int $projectId,
        string $scopeLevel,
        string $varKey,
        string $varValue,
        string $valueType = 'string'
    ): array {
        $existing = $this->findByKey($tenantId, $subtenantId, $projectId, $varKey);
        if ($existing !== null) {
            $stmt = Connection::get()->prepare(
                'UPDATE maniforge_scope_variables
                 SET var_value = :var_value, value_type = :value_type, scope_level = :scope_level
                 WHERE id = :id'
            );
            $stmt->execute([
                ':var_value' => $varValue,
                ':value_type' => $valueType,
                ':scope_level' => $scopeLevel,
                ':id' => (int) $existing['id'],
            ]);

            return $this->findById((int) $existing['id']) ?? $existing;
        }

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_scope_variables (
                tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level
             ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :var_key, :var_value, :value_type, :scope_level
             )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':project_id' => $projectId,
            ':var_key' => $varKey,
            ':var_value' => $varValue,
            ':value_type' => $valueType,
            ':scope_level' => $scopeLevel,
        ]);

        $id = (int) Connection::get()->lastInsertId();

        return $this->findById($id) ?? [
            'id' => $id,
            'tenant_id' => $tenantId,
            'subtenant_id' => $subtenantId,
            'project_id' => $projectId,
            'key' => $varKey,
            'value' => $varValue,
            'scope_level' => $scopeLevel,
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, tenant_id, subtenant_id, project_id, var_key, var_value, value_type, scope_level, created_at, updated_at
             FROM maniforge_scope_variables WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
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
            'key' => (string) $row['var_key'],
            'value' => (string) $row['var_value'],
            'value_type' => (string) $row['value_type'],
            'scope_level' => (string) $row['scope_level'],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
