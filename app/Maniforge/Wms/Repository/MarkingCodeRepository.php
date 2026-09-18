<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Repository;

use App\Database\Connection;
use App\Maniforge\Products\Repository\ProductRepository;

final class MarkingCodeRepository
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /**
     * @param array{product_id?: int, status?: string, search?: string, limit?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $sql = 'SELECT m.* FROM maniforge_wms_marking_codes m WHERE m.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['product_id'])) {
            $sql .= ' AND m.product_id = :product_id';
            $params[':product_id'] = (int) $filters['product_id'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND m.status = :status';
            $params[':status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (m.code_full LIKE :search OR m.gtin LIKE :search OR m.serial_number LIKE :search)';
            $params[':search'] = '%' . (string) $filters['search'] . '%';
        }

        $sql .= ' ORDER BY m.id DESC';
        $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 50;
        $sql .= ' LIMIT ' . $limit;

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapRow($row);
            $product = $this->products->findVisibleById($session, (int) $mapped['product_id']);
            if ($product === null) {
                continue;
            }
            $out[] = $mapped;
        }

        return $out;
    }
    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_wms_marking_codes WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function traceMovements(string $tenantId, int $markingId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT m.id AS movement_id, m.doc_number, m.movement_type, m.posted_at,
                    l.line_no, l.qty_delta, l.stock_id, l.pack_unit_id,
                    s.code AS stock_code
             FROM maniforge_inv_movement_lines l
             INNER JOIN maniforge_inv_movements m ON m.id = l.movement_id
             INNER JOIN maniforge_wh_stocks s ON s.id = l.stock_id
             WHERE m.tenant_id = :tenant_id AND l.marking_code_id = :marking_id
             ORDER BY m.posted_at ASC, l.line_no ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':marking_id' => $markingId]);

        return $stmt->fetchAll() ?: [];
    }

    public function findByGtin(string $tenantId, string $gtinOrEan13): ?array
    {
        $gtin = preg_replace('/\D/', '', $gtinOrEan13) ?? '';
        $candidates = [$gtin];
        if (strlen($gtin) === 13) {
            $candidates[] = '0' . $gtin;
        }
        if (strlen($gtin) === 14 && str_starts_with($gtin, '0')) {
            $candidates[] = substr($gtin, 1);
        }

        foreach (array_unique($candidates) as $g) {
            $stmt = Connection::get()->prepare(
                'SELECT * FROM maniforge_wms_marking_codes WHERE tenant_id = :tenant_id AND gtin = :gtin LIMIT 1'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':gtin' => $g]);
            $row = $stmt->fetch();
            if (is_array($row)) {
                return $this->mapRow($row);
            }
        }

        return null;
    }

    public function findByCode(string $tenantId, string $codeFull): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_wms_marking_codes WHERE tenant_id = :tenant_id AND code_full = :code LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':code' => trim($codeFull)]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array{product_id: int, code_full: string, code_type: string, gtin?: ?string, serial?: ?string, crypto_tail?: ?string} $row
     */
    public function create(string $tenantId, array $row): int
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_wms_marking_codes (
                tenant_id, product_id, code_full, code_type, gtin, serial_number, crypto_tail, status
            ) VALUES (
                :tenant_id, :product_id, :code_full, :code_type, :gtin, :serial, :crypto, :status
            )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => (int) $row['product_id'],
            ':code_full' => trim((string) $row['code_full']),
            ':code_type' => (string) $row['code_type'],
            ':gtin' => $row['gtin'] ?? null,
            ':serial' => $row['serial'] ?? null,
            ':crypto' => $row['crypto_tail'] ?? null,
            ':status' => 'available',
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function updateStatus(int $id, string $tenantId, string $status, ?int $packUnitId = null, ?int $stockId = null): void
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_wms_marking_codes
             SET status = :status, pack_unit_id = COALESCE(:pack_id, pack_unit_id), stock_id = COALESCE(:stock_id, stock_id)
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':status' => $status,
            ':pack_id' => $packUnitId,
            ':stock_id' => $stockId,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByPackUnit(int $packUnitId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT m.* FROM maniforge_wms_marking_codes m
             INNER JOIN maniforge_wms_pack_contents c ON c.marking_code_id = m.id
             WHERE c.parent_pack_unit_id = :pack_id
             ORDER BY c.line_no ASC'
        );
        $stmt->execute([':pack_id' => $packUnitId]);

        return array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
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
            'product_id' => (int) ($row['product_id'] ?? 0),
            'code_full' => (string) ($row['code_full'] ?? ''),
            'code_type' => (string) ($row['code_type'] ?? 'kiz'),
            'gtin' => $row['gtin'] ?? null,
            'serial_number' => $row['serial_number'] ?? null,
            'crypto_tail' => $row['crypto_tail'] ?? null,
            'status' => (string) ($row['status'] ?? 'available'),
            'pack_unit_id' => isset($row['pack_unit_id']) && $row['pack_unit_id'] !== null ? (int) $row['pack_unit_id'] : null,
            'stock_id' => isset($row['stock_id']) && $row['stock_id'] !== null ? (int) $row['stock_id'] : null,
        ];
    }
}
