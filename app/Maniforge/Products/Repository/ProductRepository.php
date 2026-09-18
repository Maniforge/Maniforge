<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\EntityScopeResolver;

final class ProductRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
    ) {
    }

    /**
     * @param array{search?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('p');
        $delegSql = $this->scope->stockDelegatedReadSql('p');
        $params = array_merge(
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId),
            [':scope_local_tenant' => $tenantId]
        );

        $sql = 'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                       p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                       p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                       p.created_by, p.updated_by, p.created_at, p.updated_at
                FROM maniforge_products p
                WHERE (
                    (p.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                    OR ' . $delegSql . '
                )';

        $status = (string) ($filters['status'] ?? 'active');
        if ($status !== 'all') {
            $sql .= ' AND p.status = :status';
            $params[':status'] = $status;
        }

        if (!empty($filters['search'])) {
            $search = (string) $filters['search'];
            $sql .= ' AND (p.name LIKE :search OR p.code LIKE :search OR p.barcode_ean13 = :ean_exact)';
            $params[':search'] = '%' . $search . '%';
            $eanNorm = \App\Maniforge\Products\Support\Ean13::normalize($search);
            $params[':ean_exact'] = ($eanNorm['ok'] ?? false) === true
                ? (string) $eanNorm['ean13']
                : '__no_match__';
        }

        $sql .= ' ORDER BY p.name ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
    }

    public function findVisibleById(array $session, int $id): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('p');
        $delegSql = $this->scope->stockDelegatedReadSql('p');
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                    p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                    p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                    p.created_by, p.updated_by, p.created_at, p.updated_at
             FROM maniforge_products p
             WHERE p.id = :id AND (
                 (p.tenant_id = :scope_local_tenant AND ' . $visSql . ')
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

    public function findVisibleByEan13(array $session, string $ean13): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('p');
        $delegSql = $this->scope->stockDelegatedReadSql('p');
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                    p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                    p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                    p.created_by, p.updated_by, p.created_at, p.updated_at
             FROM maniforge_products p
             WHERE p.barcode_ean13 = :ean13 AND p.status = :active AND (
                 (p.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                 OR ' . $delegSql . '
             ) LIMIT 1'
        );
        $stmt->execute(array_merge(
            [
                ':ean13' => $ean13,
                ':active' => 'active',
                ':scope_local_tenant' => $tenantId,
            ],
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId)
        ));
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByEan13InTenant(string $tenantId, string $ean13): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                    p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                    p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                    p.created_by, p.updated_by, p.created_at, p.updated_at
             FROM maniforge_products p
             WHERE p.tenant_id = :tenant_id AND p.barcode_ean13 = :ean13 LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':ean13' => $ean13]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                    p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                    p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                    p.created_by, p.updated_by, p.created_at, p.updated_at
             FROM maniforge_products p
             WHERE p.id = :id AND p.tenant_id = :tenant_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $scopeRow
     */
    public function findByCodeInWriteScope(array $scopeRow, string $code): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.tenant_id, p.subtenant_id, p.project_id, p.scope_visibility,
                    p.shared_subtenant_ids_json, p.shared_grant_tenant_ids_json,
                    p.code, p.barcode_ean13, p.name, p.unit, p.description, p.attributes_json, p.status,
                    p.created_by, p.updated_by, p.created_at, p.updated_at
             FROM maniforge_products p
             WHERE p.code = :code AND p.tenant_id = :tenant_id AND p.subtenant_id = :subtenant_id
               AND (p.project_id <=> :project_id)
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
     * @param array<string, mixed>|null $attributes
     */
    public function create(
        array $scopeRow,
        string $code,
        string $name,
        string $unit,
        ?string $description,
        ?array $attributes,
        int $createdBy,
        ?string $barcodeEan13 = null,
    ): array {
        $sharedJson = null;
        $shared = $scopeRow['shared_subtenant_ids'] ?? null;
        if (is_array($shared) && $shared !== []) {
            $sharedJson = json_encode(array_values($shared), JSON_UNESCAPED_UNICODE);
        }

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_products (
                tenant_id, subtenant_id, project_id, scope_visibility, shared_subtenant_ids_json,
                code, barcode_ean13, name, unit, description, attributes_json, status, created_by
            ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :scope_visibility, :shared_json,
                :code, :barcode_ean13, :name, :unit, :description, :attributes_json, :status, :created_by
            )'
        );
        $stmt->execute([
            ':tenant_id' => (string) $scopeRow['tenant_id'],
            ':subtenant_id' => (string) $scopeRow['subtenant_id'],
            ':project_id' => $scopeRow['project_id'] ?? null,
            ':scope_visibility' => (string) $scopeRow['scope_visibility'],
            ':shared_json' => $sharedJson,
            ':code' => strtolower(trim($code)),
            ':barcode_ean13' => $barcodeEan13,
            ':name' => trim($name),
            ':unit' => trim($unit) !== '' ? trim($unit) : 'pcs',
            ':description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            ':attributes_json' => $attributes === null ? null : json_encode($attributes, JSON_UNESCAPED_UNICODE),
            ':status' => 'active',
            ':created_by' => $createdBy,
        ]);

        $id = (int) Connection::get()->lastInsertId();

        return $this->findByIdInTenant($id, (string) $scopeRow['tenant_id']) ?? ['id' => $id];
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

        foreach (['name', 'unit', 'code', 'status', 'description', 'barcode_ean13'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $fields[$key];
            }
        }

        if (array_key_exists('attributes', $fields)) {
            $sets[] = 'attributes_json = :attributes_json';
            $attrs = $fields['attributes'];
            $params[':attributes_json'] = $attrs === null ? null : json_encode($attrs, JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('shared_grant_tenant_ids_json', $fields)) {
            $sets[] = 'shared_grant_tenant_ids_json = :shared_grant_json';
            $params[':shared_grant_json'] = $fields['shared_grant_tenant_ids_json'];
        }

        $sql = 'UPDATE maniforge_products SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $attrsRaw = $row['attributes_json'] ?? null;
        $attributes = null;
        if (is_string($attrsRaw) && $attrsRaw !== '') {
            $decoded = json_decode($attrsRaw, true);
            $attributes = is_array($decoded) ? $decoded : null;
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
            'barcode_ean13' => isset($row['barcode_ean13']) && $row['barcode_ean13'] !== null
                ? (string) $row['barcode_ean13'] : null,
            'barcode' => isset($row['barcode_ean13']) && $row['barcode_ean13'] !== null
                ? ['type' => 'ean13', 'value' => (string) $row['barcode_ean13']] : null,
            'name' => (string) ($row['name'] ?? ''),
            'unit' => (string) ($row['unit'] ?? 'pcs'),
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'attributes' => $attributes,
            'status' => (string) ($row['status'] ?? 'active'),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
