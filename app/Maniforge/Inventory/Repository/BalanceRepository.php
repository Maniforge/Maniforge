<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\EntityScopeResolver;

final class BalanceRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
        private readonly ReserveRepository $reserves = new ReserveRepository(),
    ) {
    }

    public function availableQtyForPair(string $tenantId, int $productId, int $stockId): string
    {
        $onHand = $this->qtyForPair($tenantId, $productId, $stockId);
        $reserved = $this->reserves->sumActiveForPair($tenantId, $productId, $stockId);

        return bcsub($onHand, $reserved, 6);
    }

    /**
     * @param array{product_id?: int, stock_id?: int, non_zero?: bool} $filters
     * @return list<array<string, mixed>>
     */
    public function listVisible(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $projectId = $this->scope->sessionProjectId($session);
        $pVis = $this->scope->stockVisibilitySql('p', 'p');
        $pDeleg = $this->scope->stockDelegatedReadSql('p', 'p');
        $sVis = $this->scope->stockVisibilitySql('s', 's');
        $sDeleg = $this->scope->stockDelegatedReadSql('s', 's');
        $params = array_merge(
            $this->scope->stockVisibilityParams($session, $projectId, 'p'),
            $this->scope->stockDelegatedReadParams($session, $projectId, 'p'),
            $this->scope->stockVisibilityParams($session, $projectId, 's'),
            $this->scope->stockDelegatedReadParams($session, $projectId, 's'),
            [
                ':balance_tenant' => $tenantId,
                ':scope_local_tenant' => $tenantId,
                ':scope_local_tenant_s' => $tenantId,
            ]
        );

        $sql = 'SELECT b.id, b.tenant_id, b.product_id, b.stock_id, b.qty, b.updated_at,
                       p.code AS product_code, p.name AS product_name, p.unit AS product_unit,
                       s.code AS stock_code, s.name AS stock_name, s.type AS stock_type
                FROM maniforge_inv_balances b
                INNER JOIN maniforge_products p ON p.id = b.product_id
                INNER JOIN maniforge_wh_stocks s ON s.id = b.stock_id AND s.tenant_id = b.tenant_id
                WHERE b.tenant_id = :balance_tenant
                  AND (
                      (p.tenant_id = :scope_local_tenant AND ' . $pVis . ')
                      OR ' . $pDeleg . '
                  )
                  AND (
                      (s.tenant_id = :scope_local_tenant_s AND ' . $sVis . ')
                      OR ' . $sDeleg . '
                  )';

        if (!empty($filters['product_id'])) {
            $sql .= ' AND b.product_id = :product_id';
            $params[':product_id'] = (int) $filters['product_id'];
        }
        if (!empty($filters['stock_id'])) {
            $sql .= ' AND b.stock_id = :stock_id';
            $params[':stock_id'] = (int) $filters['stock_id'];
        }
        if (!empty($filters['non_zero'])) {
            $sql .= ' AND b.qty <> 0';
        }

        $sql .= ' ORDER BY p.name ASC, s.name ASC';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        $rows = array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
        foreach ($rows as $i => $row) {
            $reserved = $this->reserves->sumActiveForPair(
                $tenantId,
                (int) $row['product_id'],
                (int) $row['stock_id']
            );
            $rows[$i]['qty_reserved'] = $reserved;
            $rows[$i]['qty_available'] = bcsub((string) $row['qty'], $reserved, 6);
        }

        return $rows;
    }

    public function findVisibleByPair(array $session, int $productId, int $stockId): ?array
    {
        $items = $this->listVisible($session, [
            'product_id' => $productId,
            'stock_id' => $stockId,
        ]);

        return $items[0] ?? null;
    }

    public function findByPairInTenant(string $tenantId, int $productId, int $stockId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT b.id, b.tenant_id, b.product_id, b.stock_id, b.qty, b.updated_at
             FROM maniforge_inv_balances b
             WHERE b.tenant_id = :tenant_id AND b.product_id = :product_id AND b.stock_id = :stock_id
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':stock_id' => $stockId,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @internal posting only
     */
    public function applyDelta(string $tenantId, int $productId, int $stockId, string $qtyDelta): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_balances (tenant_id, product_id, stock_id, qty)
             VALUES (:tenant_id, :product_id, :stock_id, :qty)
             ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':stock_id' => $stockId,
            ':qty' => $qtyDelta,
        ]);
    }

    public function qtyForPair(string $tenantId, int $productId, int $stockId): string
    {
        $row = $this->findByPairInTenant($tenantId, $productId, $stockId);

        return (string) ($row['qty'] ?? '0');
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
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'product_code' => isset($row['product_code']) ? (string) $row['product_code'] : null,
            'product_name' => isset($row['product_name']) ? (string) $row['product_name'] : null,
            'product_unit' => isset($row['product_unit']) ? (string) $row['product_unit'] : null,
            'stock_code' => isset($row['stock_code']) ? (string) $row['stock_code'] : null,
            'stock_name' => isset($row['stock_name']) ? (string) $row['stock_name'] : null,
            'stock_type' => isset($row['stock_type']) ? (string) $row['stock_type'] : null,
        ];
    }
}
