<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Warehouses\Repository\StockRepository;

final class ReserveRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StockRepository $stocks = new StockRepository(),
    ) {
    }

    public function sumActiveForPair(string $tenantId, int $productId, int $stockId): string
    {
        $stmt = Connection::get()->prepare(
            'SELECT COALESCE(SUM(qty), 0) AS total
             FROM maniforge_inv_reserves
             WHERE tenant_id = :tenant_id AND product_id = :product_id AND stock_id = :stock_id AND status = :status'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':stock_id' => $stockId,
            ':status' => 'active',
        ]);
        $row = $stmt->fetch();

        return (string) ($row['total'] ?? '0');
    }

    public function findActiveByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_inv_reserves WHERE id = :id AND tenant_id = :tenant_id AND status = :status LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':status' => 'active']);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array{product_id?: int, stock_id?: int, ref_code?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $status = (string) ($filters['status'] ?? 'active');
        $sql = 'SELECT r.* FROM maniforge_inv_reserves r WHERE r.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($status !== 'all') {
            $sql .= ' AND r.status = :status';
            $params[':status'] = $status;
        }
        if (!empty($filters['product_id'])) {
            $sql .= ' AND r.product_id = :product_id';
            $params[':product_id'] = (int) $filters['product_id'];
        }
        if (!empty($filters['stock_id'])) {
            $sql .= ' AND r.stock_id = :stock_id';
            $params[':stock_id'] = (int) $filters['stock_id'];
        }
        if (!empty($filters['ref_code'])) {
            $sql .= ' AND r.ref_code = :ref_code';
            $params[':ref_code'] = (string) $filters['ref_code'];
        }

        $sql .= ' ORDER BY r.id DESC LIMIT 100';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = $this->mapRow($row);
            if ($this->products->findVisibleById($session, (int) $mapped['product_id']) === null) {
                continue;
            }
            if ($this->stocks->findVisibleById($session, (int) $mapped['stock_id']) === null) {
                continue;
            }
            $out[] = $mapped;
        }

        return $out;
    }

    public function create(
        string $tenantId,
        int $productId,
        int $stockId,
        string $qty,
        string $refCode,
        ?string $note,
        int $createdBy,
    ): int {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_reserves (tenant_id, product_id, stock_id, qty, ref_code, note, status, created_by)
             VALUES (:tenant_id, :product_id, :stock_id, :qty, :ref_code, :note, :status, :created_by)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':stock_id' => $stockId,
            ':qty' => $qty,
            ':ref_code' => strtolower(trim($refCode)),
            ':note' => $note,
            ':status' => 'active',
            ':created_by' => $createdBy,
        ]);

        return (int) Connection::get()->lastInsertId();
    }

    public function release(int $id, string $tenantId, int $releasedBy): bool
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_inv_reserves
             SET status = :released, released_by = :by, released_at = NOW()
             WHERE id = :id AND tenant_id = :tenant_id AND status = :active'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':released' => 'released',
            ':active' => 'active',
            ':by' => $releasedBy,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function releaseActiveByRefCode(string $tenantId, string $refCode, int $releasedBy): int
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_inv_reserves
             SET status = :released, released_by = :by, released_at = NOW()
             WHERE tenant_id = :tenant_id AND ref_code = :ref_code AND status = :active'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':ref_code' => strtolower(trim($refCode)),
            ':released' => 'released',
            ':active' => 'active',
            ':by' => $releasedBy,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return list<array{product_id: int, total_qty: string, stock_count: int}>
     */
    public function summaryByProduct(array $session, ?int $productId = null): array
    {
        $tenantId = (string) $session['tenant_id'];
        $sql = 'SELECT b.product_id,
                       SUM(b.qty) AS total_on_hand,
                       COUNT(DISTINCT b.stock_id) AS stock_nodes
                FROM maniforge_inv_balances b
                WHERE b.tenant_id = :tenant_id AND b.qty <> 0';
        $params = [':tenant_id' => $tenantId];
        if ($productId !== null && $productId > 0) {
            $sql .= ' AND b.product_id = :product_id';
            $params[':product_id'] = $productId;
        }
        $sql .= ' GROUP BY b.product_id ORDER BY total_on_hand DESC LIMIT 200';

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $pid = (int) ($row['product_id'] ?? 0);
            if ($this->products->findVisibleById($session, $pid) === null) {
                continue;
            }
            $reserved = '0';
            $resStmt = Connection::get()->prepare(
                'SELECT COALESCE(SUM(qty), 0) AS r FROM maniforge_inv_reserves
                 WHERE tenant_id = :t AND product_id = :p AND status = :s'
            );
            $resStmt->execute([':t' => $tenantId, ':p' => $pid, ':s' => 'active']);
            $resRow = $resStmt->fetch();
            if (is_array($resRow)) {
                $reserved = (string) ($resRow['r'] ?? '0');
            }
            $onHand = (string) ($row['total_on_hand'] ?? '0');
            $out[] = [
                'product_id' => $pid,
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
                'qty_available' => bcsub($onHand, $reserved, 6),
                'stock_nodes' => (int) ($row['stock_nodes'] ?? 0),
            ];
        }

        return $out;
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
            'stock_id' => (int) ($row['stock_id'] ?? 0),
            'qty' => (string) ($row['qty'] ?? '0'),
            'ref_code' => (string) ($row['ref_code'] ?? ''),
            'note' => $row['note'] ?? null,
            'status' => (string) ($row['status'] ?? 'active'),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
