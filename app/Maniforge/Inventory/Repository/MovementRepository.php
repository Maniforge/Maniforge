<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\EntityScopeResolver;

final class MovementRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
    ) {
    }

    /**
     * @param array{movement_type?: string, limit?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('m');
        $delegSql = $this->scope->stockDelegatedReadSql('m');
        $params = array_merge(
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId),
            [':scope_local_tenant' => $tenantId]
        );

        $sql = 'SELECT m.id, m.tenant_id, m.subtenant_id, m.project_id, m.scope_visibility,
                       m.doc_number, m.movement_type, m.status, m.note, m.metadata_json,
                       m.created_by, m.posted_by, m.posted_at, m.created_at
                FROM maniforge_inv_movements m
                WHERE (
                    (m.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                    OR ' . $delegSql . '
                )';

        if (!empty($filters['movement_type'])) {
            $sql .= ' AND m.movement_type = :movement_type';
            $params[':movement_type'] = (string) $filters['movement_type'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND m.status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        $sql .= ' ORDER BY COALESCE(m.posted_at, m.created_at) DESC, m.id DESC';
        $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 50;
        $sql .= ' LIMIT ' . $limit;

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapHeader(...), $stmt->fetchAll() ?: []);
    }

    public function findVisibleById(array $session, int $id): ?array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $visSql = $this->scope->stockVisibilitySql('m');
        $delegSql = $this->scope->stockDelegatedReadSql('m');
        $stmt = Connection::get()->prepare(
            'SELECT m.id, m.tenant_id, m.subtenant_id, m.project_id, m.scope_visibility,
                    m.shared_subtenant_ids_json, m.shared_grant_tenant_ids_json,
                    m.doc_number, m.movement_type, m.status, m.note, m.metadata_json,
                    m.created_by, m.posted_by, m.posted_at, m.created_at
             FROM maniforge_inv_movements m
             WHERE m.id = :id AND (
                 (m.tenant_id = :scope_local_tenant AND ' . $visSql . ')
                 OR ' . $delegSql . '
             ) LIMIT 1'
        );
        $stmt->execute(array_merge(
            [':id' => $id, ':scope_local_tenant' => $tenantId],
            $this->scope->stockVisibilityParams($session, $projectId),
            $this->scope->stockDelegatedReadParams($session, $projectId)
        ));
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $header = $this->mapHeader($row);
        $header['lines'] = $this->linesForMovement($id);

        return $header;
    }

    /**
     * @param array<string, mixed> $scopeRow
     * @param list<array{product_id: int, stock_id: int, qty_delta: string}> $lines
     */
    public function insertPosted(
        array $scopeRow,
        string $docNumber,
        string $movementType,
        ?string $note,
        ?array $metadata,
        int $actorId,
        array $lines,
    ): int {
        return $this->insertWithStatus($scopeRow, $docNumber, $movementType, $note, $metadata, $actorId, $lines, 'posted', true);
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function insertDraft(
        array $scopeRow,
        string $docNumber,
        string $movementType,
        ?string $note,
        ?array $metadata,
        int $actorId,
        array $lines,
    ): int {
        return $this->insertWithStatus($scopeRow, $docNumber, $movementType, $note, $metadata, $actorId, $lines, 'draft', false);
    }

    public function markPosted(int $movementId, string $tenantId, int $postedBy): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_inv_movements
             SET status = :posted, posted_by = :by, posted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id AND status = :draft'
        );
        $stmt->execute([
            ':id' => $movementId,
            ':tenant_id' => $tenantId,
            ':posted' => 'posted',
            ':draft' => 'draft',
            ':by' => $postedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function deleteDraft(int $movementId, string $tenantId): bool
    {
        $pdo = Connection::get();
        $check = $pdo->prepare(
            'SELECT id FROM maniforge_inv_movements WHERE id = :id AND tenant_id = :tenant_id AND status = :draft LIMIT 1'
        );
        $check->execute([':id' => $movementId, ':tenant_id' => $tenantId, ':draft' => 'draft']);
        if ($check->fetch() === false) {
            return false;
        }

        $pdo->prepare('DELETE FROM maniforge_inv_movement_lines WHERE movement_id = :id')->execute([':id' => $movementId]);
        $del = $pdo->prepare('DELETE FROM maniforge_inv_movements WHERE id = :id AND tenant_id = :tenant_id AND status = :draft');
        $del->execute([':id' => $movementId, ':tenant_id' => $tenantId, ':draft' => 'draft']);

        return $del->rowCount() > 0;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function insertWithStatus(
        array $scopeRow,
        string $docNumber,
        string $movementType,
        ?string $note,
        ?array $metadata,
        int $actorId,
        array $lines,
        string $status,
        bool $postNow,
    ): int {
        $metaJson = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE);
        $sharedJson = null;
        $shared = $scopeRow['shared_subtenant_ids'] ?? null;
        if (is_array($shared) && $shared !== []) {
            $sharedJson = json_encode(array_values($shared), JSON_UNESCAPED_UNICODE);
        }
        $grantJson = null;
        $grant = $scopeRow['delegation_share_tenant_ids'] ?? $scopeRow['shared_grant_tenant_ids'] ?? null;
        if (is_array($grant) && $grant !== []) {
            $grantJson = json_encode(array_values($grant), JSON_UNESCAPED_UNICODE);
        }

        $postedAtSql = $postNow ? 'NOW()' : 'NULL';
        $postedBy = $postNow ? $actorId : null;

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_movements (
                tenant_id, subtenant_id, project_id, scope_visibility,
                shared_subtenant_ids_json, shared_grant_tenant_ids_json,
                doc_number, movement_type, status, note, metadata_json,
                created_by, posted_by, posted_at
            ) VALUES (
                :tenant_id, :subtenant_id, :project_id, :scope_visibility,
                :shared_json, :grant_json,
                :doc_number, :movement_type, :status, :note, :metadata_json,
                :created_by, :posted_by, ' . $postedAtSql . '
            )'
        );
        $stmt->execute([
            ':tenant_id' => (string) $scopeRow['tenant_id'],
            ':subtenant_id' => (string) $scopeRow['subtenant_id'],
            ':project_id' => $scopeRow['project_id'] ?? null,
            ':scope_visibility' => (string) $scopeRow['scope_visibility'],
            ':shared_json' => $sharedJson,
            ':grant_json' => $grantJson,
            ':doc_number' => $docNumber,
            ':movement_type' => $movementType,
            ':status' => $status,
            ':note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ':metadata_json' => $metaJson,
            ':created_by' => $actorId,
            ':posted_by' => $postedBy,
        ]);

        $movementId = (int) Connection::get()->lastInsertId();
        $this->insertLines($movementId, $lines);

        return $movementId;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function insertLines(int $movementId, array $lines): void
    {
        $lineStmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_movement_lines (
                movement_id, line_no, product_id, stock_id, qty_delta,
                pack_unit_id, marking_code_id, batch_code, lot_code, lot_id
            ) VALUES (
                :movement_id, :line_no, :product_id, :stock_id, :qty_delta,
                :pack_unit_id, :marking_code_id, :batch_code, :lot_code, :lot_id
            )'
        );
        $lineNo = 1;
        foreach ($lines as $line) {
            $lineStmt->execute([
                ':movement_id' => $movementId,
                ':line_no' => $lineNo++,
                ':product_id' => (int) $line['product_id'],
                ':stock_id' => (int) $line['stock_id'],
                ':qty_delta' => (string) $line['qty_delta'],
                ':pack_unit_id' => isset($line['pack_unit_id']) ? (int) $line['pack_unit_id'] : null,
                ':marking_code_id' => isset($line['marking_code_id']) ? (int) $line['marking_code_id'] : null,
                ':batch_code' => isset($line['batch_code']) ? (string) $line['batch_code'] : null,
                ':lot_code' => isset($line['lot_code']) ? (string) $line['lot_code'] : null,
                ':lot_id' => isset($line['lot_id']) ? (int) $line['lot_id'] : null,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function linesForMovement(int $movementId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT l.id, l.movement_id, l.line_no, l.product_id, l.stock_id, l.qty_delta,
                    l.pack_unit_id, l.marking_code_id, l.batch_code, l.lot_code, l.lot_id,
                    p.code AS product_code, p.name AS product_name,
                    s.code AS stock_code, s.name AS stock_name
             FROM maniforge_inv_movement_lines l
             INNER JOIN maniforge_products p ON p.id = l.product_id
             INNER JOIN maniforge_wh_stocks s ON s.id = l.stock_id
             WHERE l.movement_id = :movement_id
             ORDER BY l.line_no ASC'
        );
        $stmt->execute([':movement_id' => $movementId]);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'line_no' => (int) ($row['line_no'] ?? 0),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'stock_id' => (int) ($row['stock_id'] ?? 0),
                'qty_delta' => (string) ($row['qty_delta'] ?? '0'),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
                'stock_code' => (string) ($row['stock_code'] ?? ''),
                'stock_name' => (string) ($row['stock_name'] ?? ''),
                'pack_unit_id' => isset($row['pack_unit_id']) && $row['pack_unit_id'] !== null
                    ? (int) $row['pack_unit_id'] : null,
                'marking_code_id' => isset($row['marking_code_id']) && $row['marking_code_id'] !== null
                    ? (int) $row['marking_code_id'] : null,
                'batch_code' => $row['batch_code'] ?? null,
                'lot_code' => $row['lot_code'] ?? null,
                'lot_id' => isset($row['lot_id']) && $row['lot_id'] !== null ? (int) $row['lot_id'] : null,
            ];
        }, $stmt->fetchAll() ?: []);
    }

    public function markReversed(int $movementId, int $reversalMovementId): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_inv_movements
             SET metadata_json = JSON_SET(COALESCE(metadata_json, JSON_OBJECT()), \'$.reversed_by_movement_id\', :rev_id)
             WHERE id = :id'
        );
        $stmt->execute([':id' => $movementId, ':rev_id' => $reversalMovementId]);
    }

    public function hasReversal(int $movementId): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT id FROM maniforge_inv_movements
             WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, \'$.reversal_of\')) AS UNSIGNED) = :mid
             LIMIT 1'
        );
        $stmt->execute([':mid' => $movementId]);

        return $stmt->fetch() !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapHeader(array $row): array
    {
        $meta = null;
        if (isset($row['metadata_json']) && is_string($row['metadata_json']) && $row['metadata_json'] !== '') {
            $decoded = json_decode($row['metadata_json'], true);
            $meta = is_array($decoded) ? $decoded : null;
        }

        $grantShared = null;
        if (isset($row['shared_grant_tenant_ids_json']) && is_string($row['shared_grant_tenant_ids_json'])) {
            $decoded = json_decode($row['shared_grant_tenant_ids_json'], true);
            $grantShared = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'subtenant_id' => (string) ($row['subtenant_id'] ?? ''),
            'project_id' => isset($row['project_id']) && $row['project_id'] !== null ? (int) $row['project_id'] : null,
            'scope_visibility' => (string) ($row['scope_visibility'] ?? 'project'),
            'delegation_share_tenant_ids' => $grantShared,
            'doc_number' => (string) ($row['doc_number'] ?? ''),
            'movement_type' => (string) ($row['movement_type'] ?? ''),
            'status' => (string) ($row['status'] ?? 'posted'),
            'note' => isset($row['note']) ? (string) $row['note'] : null,
            'metadata' => $meta,
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'posted_by' => isset($row['posted_by']) ? (int) $row['posted_by'] : null,
            'posted_at' => isset($row['posted_at']) && $row['posted_at'] !== null ? (string) $row['posted_at'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
