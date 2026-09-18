<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Products\Repository\ProductRepository;

final class LotRepository
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    public function findByIdInTenant(int $id, string $tenantId): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_inv_lots WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function findByKey(string $tenantId, int $productId, string $batchCode, string $lotCode): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT * FROM maniforge_inv_lots
             WHERE tenant_id = :tenant_id AND product_id = :product_id
               AND batch_code = :batch AND lot_code = :lot LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':batch' => $batchCode,
            ':lot' => $lotCode,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    /**
     * @param array{product_id?: int, batch_code?: string, status?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function listInTenant(array $session, array $filters = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $sql = 'SELECT l.* FROM maniforge_inv_lots l WHERE l.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if (!empty($filters['product_id'])) {
            $sql .= ' AND l.product_id = :product_id';
            $params[':product_id'] = (int) $filters['product_id'];
        }
        if (!empty($filters['batch_code'])) {
            $sql .= ' AND l.batch_code = :batch_code';
            $params[':batch_code'] = (string) $filters['batch_code'];
        }
        if (!empty($filters['status'])) {
            $sql .= ' AND l.status = :status';
            $params[':status'] = (string) $filters['status'];
        } else {
            $sql .= ' AND l.status = :status';
            $params[':status'] = 'active';
        }

        $sql .= ' ORDER BY l.expires_at ASC, l.id DESC LIMIT 100';
        $stmt = Connection::get()->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->products->findVisibleById($session, (int) ($row['product_id'] ?? 0)) === null) {
                continue;
            }
            $out[] = $this->mapRow($row);
        }

        return $out;
    }

    public function create(
        string $tenantId,
        int $productId,
        string $batchCode,
        string $lotCode,
        ?string $manufacturedAt,
        ?string $expiresAt,
        ?string $note,
        int $createdBy,
    ): int {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_inv_lots (
                tenant_id, product_id, batch_code, lot_code, manufactured_at, expires_at, note, status, created_by
            ) VALUES (
                :tenant_id, :product_id, :batch_code, :lot_code, :mfg, :exp, :note, :status, :created_by
            )'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':product_id' => $productId,
            ':batch_code' => $batchCode,
            ':lot_code' => $lotCode,
            ':mfg' => $manufacturedAt,
            ':exp' => $expiresAt,
            ':note' => $note,
            ':status' => 'active',
            ':created_by' => $createdBy,
        ]);

        return (int) Connection::get()->lastInsertId();
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
            'batch_code' => (string) ($row['batch_code'] ?? ''),
            'lot_code' => (string) ($row['lot_code'] ?? ''),
            'manufactured_at' => $row['manufactured_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'status' => (string) ($row['status'] ?? 'active'),
            'note' => $row['note'] ?? null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }
}
