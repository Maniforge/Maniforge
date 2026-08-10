<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Warehouses\Repository\StockRepository;

final class OrderRepository
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StockRepository $stocks = new StockRepository(),
    ) {
    }

    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_inv_orders WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $order = $this->mapHeader($row);
        $order['lines'] = $this->linesForOrder($id);

        return $order;
    }

    /**
     * @param array{status?: string, limit?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function listInTenant(string $tenantId, array $filters = []): array
    {
        $sql = 'SELECT * FROM maniforge_inv_orders WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params[':status'] = (string) $filters['status'];
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';
        $limit = isset($filters['limit']) ? max(1, min(100, (int) $filters['limit'])) : 50;
        $sql .= ' LIMIT ' . $limit;

        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        return array_map($this->mapHeader(...), $stmt->fetchAll() ?: []);
    }

    /**
     * @param list<array{product_id: int, qty_ordered: string}> $lines
     */
    public function create(
        string $tenantId,
        string $orderNumber,
        int $stockId,
        ?string $note,
        ?array $metadata,
        int $createdBy,
        array $lines,
    ): int {
        $pdo = Connection::get();
        $pdo->beginTransaction();
        try {
            $metaJson = $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                'INSERT INTO maniforge_inv_orders (tenant_id, order_number, status, stock_id, note, metadata_json, created_by)
                 VALUES (:tenant_id, :order_number, :status, :stock_id, :note, :metadata_json, :created_by)'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':order_number' => strtolower(trim($orderNumber)),
                ':status' => 'draft',
                ':stock_id' => $stockId,
                ':note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                ':metadata_json' => $metaJson,
                ':created_by' => $createdBy,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $this->insertLines($orderId, $lines);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        return $orderId;
    }

    public function updateStatus(int $id, string $tenantId, string $status, ?string $timestampField = null): bool
    {
        $allowed = ['draft', 'confirmed', 'fulfilled', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $extra = '';
        if ($timestampField === 'confirmed_at') {
            $extra = ', confirmed_at = NOW()';
        } elseif ($timestampField === 'fulfilled_at') {
            $extra = ', fulfilled_at = NOW()';
        } elseif ($timestampField === 'cancelled_at') {
            $extra = ', cancelled_at = NOW()';
        }

        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_inv_orders SET status = :status' . $extra . '
             WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':status' => $status,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function orderRefCode(array $order): string
    {
        return 'inv-order:' . strtolower((string) ($order['order_number'] ?? ''));
    }

    /**
     * @param list<array{product_id: int, qty_ordered: string}> $lines
     */
    private function insertLines(int $orderId, array $lines): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_order_lines (order_id, line_no, product_id, qty_ordered)
             VALUES (:order_id, :line_no, :product_id, :qty)'
        );
        $lineNo = 1;
        foreach ($lines as $line) {
            $stmt->execute([
                ':order_id' => $orderId,
                ':line_no' => $lineNo++,
                ':product_id' => (int) $line['product_id'],
                ':qty' => (string) $line['qty_ordered'],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function linesForOrder(int $orderId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT l.*, p.code AS product_code, p.name AS product_name
             FROM maniforge_inv_order_lines l
             INNER JOIN maniforge_products p ON p.id = l.product_id
             WHERE l.order_id = :order_id
             ORDER BY l.line_no ASC'
        );
        $stmt->execute([':order_id' => $orderId]);

        return array_map(static function (array $row): array {
            return [
                'id' => (int) ($row['id'] ?? 0),
                'line_no' => (int) ($row['line_no'] ?? 0),
                'product_id' => (int) ($row['product_id'] ?? 0),
                'qty_ordered' => (string) ($row['qty_ordered'] ?? '0'),
                'product_code' => (string) ($row['product_code'] ?? ''),
                'product_name' => (string) ($row['product_name'] ?? ''),
            ];
        }, $stmt->fetchAll() ?: []);
    }

    public function validateLinesVisible(array $session, array $lines): bool
    {
        foreach ($lines as $line) {
            if (!is_array($line)) {
                return false;
            }
            $pid = (int) ($line['product_id'] ?? 0);
            if ($pid <= 0 || $this->products->findVisibleById($session, $pid) === null) {
                return false;
            }
        }

        return $lines !== [];
    }

    public function stockVisible(array $session, int $stockId): bool
    {
        return $this->stocks->findVisibleById($session, $stockId) !== null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapHeader(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenant_id' => (string) ($row['tenant_id'] ?? ''),
            'order_number' => (string) ($row['order_number'] ?? ''),
            'status' => (string) ($row['status'] ?? 'draft'),
            'stock_id' => (int) ($row['stock_id'] ?? 0),
            'note' => $row['note'] ?? null,
            'metadata' => isset($row['metadata_json']) && is_string($row['metadata_json'])
                ? json_decode($row['metadata_json'], true) : ($row['metadata'] ?? null),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'confirmed_at' => $row['confirmed_at'] ?? null,
            'fulfilled_at' => $row['fulfilled_at'] ?? null,
            'cancelled_at' => $row['cancelled_at'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'ref_code' => 'inv-order:' . strtolower((string) ($row['order_number'] ?? '')),
        ];
    }
}
