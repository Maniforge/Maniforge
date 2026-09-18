<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\EntityScopeResolver;

final class StockRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
    ) {
    }

    /**
     * @param array{parent_id?: int|null, type?: string, search?: string, roots_only?: bool, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('s');
        $delegSql = $this->scope->stockDelegatedReadSql('s');
        $params = array_merge(
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId)
        );

        $sql = 'SELECT s.id, s.tenant_id, s.subtenant_id, s.project_id, s.scope_visibility,
                       s.shared_subtenant_ids_json, s.shared_grant_tenant_ids_json,
                       s.code, s.name, s.type, s.parent_id,
                       s.data_json, s.active, s.status, s.created_by, s.updated_by, s.created_at, s.updated_at,
                       p.code AS parent_code, p.name AS parent_name
                FROM maniforge_wh_stocks s
                LEFT JOIN maniforge_wh_stocks p ON p.id = s.parent_id
                WHERE (
                    (s.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                    OR ' . $delegSql . '
                )';
        $params[':scope_local_tenant'] = $tenantId;

        $status = (string) ($filters['status'] ?? 'active');
        if ($status !== 'all') {
            $sql .= ' AND s.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($filters['type'])) {
            $sql .= ' AND s.type = :type';
            $params[':type'] = (string) $filters['type'];
        }

        if (array_key_exists('parent_id', $filters)) {
            if ($filters['parent_id'] === null) {
                $sql .= ' AND s.parent_id IS NULL';
            } else {
                $sql .= ' AND s.parent_id = :parent_id';
                $params[':parent_id'] = (int) $filters['parent_id'];
            }
        }

        if (!empty($filters['roots_only'])) {
            $sql .= ' AND s.parent_id IS NULL';
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (s.name LIKE :search OR s.code LIKE :search)';
            $params[':search'] = '%' . (string) $filters['search'] . '%';
        }

        $sql .= ' ORDER BY s.parent_id IS NULL DESC, s.name ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
    }

    public function findVisibleById(array $session, int $id): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('s');
        $delegSql = $this->scope->stockDelegatedReadSql('s');
        $stmt = Connection::get()->prepare(
            'SELECT s.id, s.tenant_id, s.subtenant_id, s.project_id, s.scope_visibility,
                    s.shared_subtenant_ids_json, s.shared_grant_tenant_ids_json,
                    s.code, s.name, s.type, s.parent_id,
                    s.data_json, s.active, s.status, s.created_by, s.updated_by, s.created_at, s.updated_at,
                    p.code AS parent_code, p.name AS parent_name
             FROM maniforge_wh_stocks s
             LEFT JOIN maniforge_wh_stocks p ON p.id = s.parent_id
             WHERE s.id = :id AND (
                 (s.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                 OR ' . $delegSql . '
             ) LIMIT 1'
        );
        $stmt->execute(array_merge(
            [':id' => $id, ':scope_local_tenant' => $tenantId],
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId)
        ));
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT s.id, s.tenant_id, s.subtenant_id, s.project_id, s.scope_visibility,
                    s.shared_subtenant_ids_json, s.shared_grant_tenant_ids_json,
                    s.code, s.name, s.type, s.parent_id,
                    s.data_json, s.active, s.status, s.created_by, s.updated_by, s.created_at, s.updated_at
             FROM maniforge_wh_stocks s
             WHERE s.id = :id AND s.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $scopeRow from EntityScopeResolver::resolveStockWriteScope
     */
    public function findByCodeInWriteScope(array $scopeRow, string $code): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT s.id, s.tenant_id, s.subtenant_id, s.project_id, s.scope_visibility,
                    s.shared_subtenant_ids_json, s.shared_grant_tenant_ids_json,
                    s.code, s.name, s.type, s.parent_id,
                    s.data_json, s.active, s.status, s.created_by, s.updated_by, s.created_at, s.updated_at
             FROM maniforge_wh_stocks s
             WHERE s.code = :code AND s.tenant_id = :tenant_id AND s.subtenant_id = :subtenant_id
               AND (s.project_id <=> :project_id)
             LIMIT 1'
        );
        $stmt->execute([
            ':code' => strtolower(trim($code)),
            ':tenant_id' => (string) $scopeRow['tenant_id'],
            ':subtenant_id' => (string) $scopeRow['subtenant_id'],
            ':project_id' => $scopeRow['project_id'] ?? null,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $scopeRow
     * @param array<string, mixed>|null $data
     */
    public function create(
        array $scopeRow,
        string $code,
        string $name,
        string $type,
        ?int $parentId,
        ?array $data,
        int $createdBy,
    ): array {
        $sharedJson = null;
        $shared = $scopeRow['shared_subtenant_ids'] ?? null;
        if (is_array($shared) && $shared !== []) {
            $sharedJson = json_encode(array_values($shared), JSON_UNESCAPED_UNICODE);
        }

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_wh_stocks (
                tenant_id, subtenant_id, project_id, scope_visibility, shared_subtenant_ids_json,
                code, name, type, parent_id, data_json, active, status, created_by
            ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :scope_visibility, :shared_json,
                :code, :name, :type, :parent_id, :data_json, 1, :status, :created_by
            )'
        );
        $stmt->execute([
            ':tenant_id' => (string) $scopeRow['tenant_id'],
            ':subtenant_id' => (string) $scopeRow['subtenant_id'],
            ':project_id' => $scopeRow['project_id'] ?? null,
            ':scope_visibility' => (string) $scopeRow['scope_visibility'],
            ':shared_json' => $sharedJson,
            ':code' => strtolower(trim($code)),
            ':name' => trim($name),
            ':type' => trim($type),
            ':parent_id' => $parentId,
            ':data_json' => $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE),
            ':status' => 'active',
            ':created_by' => $createdBy,
        ]);

        $id = (int) Connection::get()->lastInsertId();
        $row = $this->findByIdInTenant($id, (string) $scopeRow['tenant_id']);

        return $row ?? ['id' => $id];
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, string $tenantId, array $fields, int $updatedBy): bool
    {
        $sets = ['updated_by = :updated_by'];
        $params = [
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':updated_by' => $updatedBy,
        ];

        foreach (['name', 'type', 'code', 'active', 'status'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $fields[$key];
            }
        }

        if (array_key_exists('parent_id', $fields)) {
            $sets[] = 'parent_id = :parent_id';
            $params[':parent_id'] = $fields['parent_id'];
        }

        if (array_key_exists('data', $fields)) {
            $sets[] = 'data_json = :data_json';
            $data = $fields['data'];
            $params[':data_json'] = $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('shared_grant_tenant_ids_json', $fields)) {
            $sets[] = 'shared_grant_tenant_ids_json = :shared_grant_json';
            $params[':shared_grant_json'] = $fields['shared_grant_tenant_ids_json'];
        }

        $sql = 'UPDATE maniforge_wh_stocks SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function countChildren(int $parentId, string $tenantId, bool $activeOnly = true): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM maniforge_wh_stocks
                WHERE parent_id = :parent_id AND tenant_id = :tenant_id';
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute([
            ':parent_id' => $parentId,
            ':tenant_id' => $tenantId,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['cnt'] ?? 0);
    }

    /**
     * @return list<int>
     */
    public function listDescendantIds(array $session, int $stockId): array
    {
        $all = $this->listVisible($session, ['status' => 'all']);
        $byParent = [];
        foreach ($all as $row) {
            $pid = $row['parent_id'] ?? null;
            $key = $pid === null ? 0 : (int) $pid;
            $byParent[$key][] = (int) $row['id'];
        }

        $ids = [];
        $queue = [$stockId];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($byParent[$current] ?? [] as $childId) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $dataRaw = $row['data_json'] ?? null;
        $data = null;
        if (is_string($dataRaw) && $dataRaw !== '') {
            $decoded = json_decode($dataRaw, true);
            $data = is_array($decoded) ? $decoded : null;
        }

        $sharedRaw = $row['shared_subtenant_ids_json'] ?? null;
        $shared = null;
        if (is_string($sharedRaw) && $sharedRaw !== '') {
            $decoded = json_decode($sharedRaw, true);
            $shared = is_array($decoded) ? $decoded : null;
        }

        $grantSharedRaw = $row['shared_grant_tenant_ids_json'] ?? null;
        $grantShared = null;
        if (is_string($grantSharedRaw) && $grantSharedRaw !== '') {
            $decoded = json_decode($grantSharedRaw, true);
            $grantShared = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($row['subtenant_id'] ?? ''),
            'project_id' => isset($row['project_id']) && $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'scope_visibility' => (string) ($row['scope_visibility'] ?? 'project'),
            'shared_subtenant_ids' => $shared,
            'delegation_share_tenant_ids' => $grantShared,
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'parent_code' => isset($row['parent_code']) ? (string) $row['parent_code'] : null,
            'parent_name' => isset($row['parent_name']) ? (string) $row['parent_name'] : null,
            'data' => $data,
            'active' => (bool) ($row['active'] ?? true),
            'status' => (string) ($row['status'] ?? 'active'),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
