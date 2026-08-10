<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Wms\Support\QrSsccGenerator;

final class PackUnitRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
    ) {
    }

    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_wms_pack_units WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findVisibleById(array $session, int $id): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('p', 'pk');
        $delegSql = $this->scope->stockDelegatedReadSql('p', 'pk');
        $stmt = Connection::get()->prepare(
            'SELECT p.* FROM maniforge_wms_pack_units p
             WHERE p.id = :id AND (
                 (p.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                 OR ' . $delegSql . '
             ) LIMIT 1'
        );
        $stmt->execute(array_merge(
            [':id' => $id, ':scope_local_tenant' => $tenantId],
            $this->scope->stockVisibilityParams($session, $projectId, 'pk'),
            $this->scope->stockDelegatedReadParams($session, $projectId, 'pk')
        ));
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array{unit_type?: string, status?: string, search?: string, limit?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('p', 'pk');
        $delegSql = $this->scope->stockDelegatedReadSql('p', 'pk');
        $params = array_merge(
            $this->scope->stockVisibilityParams($session, $projectId, 'pk'),
            $this->scope->stockDelegatedReadParams($session, $projectId, 'pk'),
            [':scope_local_tenant' => $tenantId]
        );

        $sql = 'SELECT p.* FROM maniforge_wms_pack_units p
                WHERE (
                    (p.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                    OR ' . $delegSql . '
                )';

        if (!empty($filters['unit_type'])) {
            $sql .= ' AND p.unit_type = :unit_type';
            $params[':unit_type'] = (string) $filters['unit_type'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND p.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (p.code LIKE :search OR p.sscc LIKE :search)';
            $params[':search'] = '%' . (string) $filters['search'] . '%';
        }

        $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';
        $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 50;
        $sql .= ' LIMIT ' . $limit;

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
    }

    public function findByQrLookup(string $tenantId, string $qrLookup): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_wms_pack_units WHERE tenant_id = :tenant_id AND qr_lookup = :lookup LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':lookup' => $qrLookup]);

        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findBySscc(string $tenantId, string $sscc): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_wms_pack_units WHERE tenant_id = :tenant_id AND sscc = :sscc LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':sscc' => $sscc]);

        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array<string, mixed> $scopeRow
     */
    public function create(array $scopeRow, string $unitType, string $code, ?int $stockId, ?int $productId, int $createdBy, ?string $sscc = null): array
    {
        $tenantId = (string) $scopeRow['tenant_id'];
        $placeholder = [
            'id' => 0,
            'tenant_id' => $tenantId,
            'unit_type' => $unitType,
            'code' => $code,
            'sscc' => $sscc,
        ];
        $qrPayload = QrSsccGenerator::qrPayload($placeholder);
        $qrLookup = QrSsccGenerator::qrLookup($qrPayload);

        $sharedJson = $this->encodeShared($scopeRow['shared_subtenant_ids'] ?? null);

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_wms_pack_units (
                tenant_id, subtenant_id, project_id, scope_visibility, shared_subtenant_ids_json,
                unit_type, code, sscc, qr_payload, qr_lookup, stock_id, product_id, status, created_by
            ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :scope_visibility, :shared_json,
                :unit_type, :code, :sscc, :qr_payload, :qr_lookup, :stock_id, :product_id, :status, :created_by
            )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => (string) $scopeRow['subtenant_id'],
            ':project_id' => $scopeRow['project_id'] ?? null,
            ':scope_visibility' => (string) $scopeRow['scope_visibility'],
            ':shared_json' => $sharedJson,
            ':unit_type' => $unitType,
            ':code' => strtolower(trim($code)),
            ':sscc' => $sscc,
            ':qr_payload' => $qrPayload,
            ':qr_lookup' => $qrLookup,
            ':stock_id' => $stockId,
            ':product_id' => $productId,
            ':status' => 'draft',
            ':created_by' => $createdBy,
        ]);
        $id = (int) Connection::get()->lastInsertId();
        $row = $this->findByIdInTenant($id, $tenantId) ?? ['id' => $id];
        $qrPayload = QrSsccGenerator::qrPayload($row);
        $this->update($id, $tenantId, [
            'qr_payload' => $qrPayload,
            'qr_lookup' => QrSsccGenerator::qrLookup($qrPayload),
        ]);

        return $this->findByIdInTenant($id, $tenantId) ?? $row;
    }

    public function deleteDraft(int $id, string $tenantId): bool
    {
        $pdo = Connection::get();
        $check = $pdo->prepare(
            'SELECT id FROM maniforge_wms_pack_units WHERE id = :id AND tenant_id = :tenant_id AND status = :draft LIMIT 1'
        );
        $check->execute([':id' => $id, ':tenant_id' => $tenantId, ':draft' => 'draft']);
        if ($check->fetch() === false) {
            return false;
        }

        $pdo->prepare('DELETE FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id = :id')->execute([':id' => $id]);
        $del = $pdo->prepare(
            'DELETE FROM maniforge_wms_pack_units WHERE id = :id AND tenant_id = :tenant_id AND status = :draft'
        );
        $del->execute([':id' => $id, ':tenant_id' => $tenantId, ':draft' => 'draft']);

        return $del->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, string $tenantId, array $fields): void
    {
        $sets = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId];
        foreach (['status', 'stock_id', 'qr_payload', 'qr_lookup', 'sscc', 'sealed_at', 'sealed_by'] as $key) {
            if (array_key_exists($key, $fields)) {
                $sets[] = "{$key} = :{$key}";
                $params[":{$key}"] = $fields[$key];
            }
        }
        if ($sets === []) {
            return;
        }
        $sql = 'UPDATE maniforge_wms_pack_units SET ' . implode(', ', $sets) . ' WHERE id = :id AND tenant_id = :tenant_id';
        Connection::get()->prepare($sql)->execute($params);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($row['subtenant_id'] ?? ''),
            'project_id' => isset($row['project_id']) && $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'scope_visibility' => (string) ($row['scope_visibility'] ?? 'project'),
            'unit_type' => (string) ($row['unit_type'] ?? ''),
            'code' => (string) ($row['code'] ?? ''),
            'sscc' => isset($row['sscc']) ? (string) $row['sscc'] : null,
            'qr_payload' => isset($row['qr_payload']) ? (string) $row['qr_payload'] : null,
            'qr_lookup' => (string) ($row['qr_lookup'] ?? ''),
            'stock_id' => isset($row['stock_id']) && $row['stock_id'] !== null ? (int) $row['stock_id'] : null,
            'product_id' => isset($row['product_id']) && $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'status' => (string) ($row['status'] ?? 'draft'),
            'sealed_at' => $row['sealed_at'] ?? null,
            'sealed_by' => isset($row['sealed_by']) ? (int) $row['sealed_by'] : null,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param list<string>|null $shared
     */
    private function encodeShared(?array $shared): ?string
    {
        if ($shared === null || $shared === []) {
            return null;
        }

        return json_encode(array_values($shared), JSON_UNESCAPED_UNICODE);
    }
}
