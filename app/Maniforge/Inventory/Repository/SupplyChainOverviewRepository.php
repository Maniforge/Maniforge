<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Repository;

use App\Database\Connection;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Rbac\Support\EntityScopeResolver;

/** Сводка по всем подсистемам учёта tenant (read-only). */
final class SupplyChainOverviewRepository
{
    public function __construct(
        private readonly EntityScopeResolver $scope = new EntityScopeResolver(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(array $session): array
    {
        $tenantId = (string) $session['tenant_id'];
        $pdo = Connection::get();

        $productsActive = $this->countVisibleProducts($session);

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS c, COALESCE(SUM(qty), 0) AS total_qty
             FROM maniforge_inv_balances WHERE tenant_id = :t AND qty <> 0'
        );
        $stmt->execute([':t' => $tenantId]);
        $bal = $stmt->fetch() ?: [];

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM maniforge_inv_movements WHERE tenant_id = :t AND posted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'
        );
        $stmt->execute([':t' => $tenantId]);
        $mov30 = $stmt->fetch() ?: [];

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS c FROM maniforge_inv_reserves WHERE tenant_id = :t AND status = :s'
        );
        $stmt->execute([':t' => $tenantId, ':s' => 'active']);
        $resActive = $stmt->fetch() ?: [];

        $wmsPacks = [];
        try {
            $stmt = $pdo->prepare(
                'SELECT status, COUNT(*) AS c FROM maniforge_wms_pack_units WHERE tenant_id = :t GROUP BY status'
            );
            $stmt->execute([':t' => $tenantId]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (is_array($row)) {
                    $wmsPacks[(string) ($row['status'] ?? '')] = (int) ($row['c'] ?? 0);
                }
            }
        } catch (\Throwable) {
            $wmsPacks = [];
        }

        $wmsMarkings = ['total' => 0, 'available' => 0, 'shipped' => 0];
        try {
            $stmt = $pdo->prepare(
                'SELECT status, COUNT(*) AS c FROM maniforge_wms_marking_codes WHERE tenant_id = :t GROUP BY status'
            );
            $stmt->execute([':t' => $tenantId]);
            foreach ($stmt->fetchAll() ?: [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $st = (string) ($row['status'] ?? '');
                $c = (int) ($row['c'] ?? 0);
                $wmsMarkings['total'] += $c;
                if ($st === 'available') {
                    $wmsMarkings['available'] = $c;
                }
                if ($st === 'shipped') {
                    $wmsMarkings['shipped'] = $c;
                }
            }
        } catch (\Throwable) {
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM maniforge_wh_stocks WHERE tenant_id = :t AND status = :s');
        $stmt->execute([':t' => $tenantId, ':s' => 'active']);
        $stocksActive = (int) (($stmt->fetch()['c'] ?? 0));

        return [
            'tenant_id' => $tenantId,
            'products_active' => $productsActive,
            'stocks_active' => $stocksActive,
            'balances' => [
                'pairs_non_zero' => (int) ($bal['c'] ?? 0),
                'total_qty' => (string) ($bal['total_qty'] ?? '0'),
            ],
            'reserves_active' => (int) ($resActive['c'] ?? 0),
            'movements_last_30d' => (int) ($mov30['c'] ?? 0),
            'wms_packs_by_status' => $wmsPacks,
            'wms_markings' => $wmsMarkings,
        ];
    }

    private function countVisibleProducts(array $session): int
    {
        $items = $this->products->listVisible($session, ['status' => 'active']);

        return count($items);
    }
}
