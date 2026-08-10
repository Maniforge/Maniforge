<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Security;

use App\Maniforge\Inventory\Repository\BalanceRepository;
use App\Maniforge\Inventory\Repository\ReserveRepository;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Warehouses\Repository\StockRepository;

final class ReserveService
{
    public function __construct(
        private readonly ReserveRepository $reserves = new ReserveRepository(),
        private readonly BalanceRepository $balances = new BalanceRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StockRepository $stocks = new StockRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $session, array $query): array
    {
        $filters = [];
        foreach (['product_id', 'stock_id', 'ref_code', 'status'] as $key) {
            if (!empty($query[$key])) {
                $filters[$key] = $query[$key];
            }
        }

        return ['ok' => true, 'status' => 200, 'items' => $this->reserves->listVisible($session, $filters)];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $productId = (int) ($input['product_id'] ?? 0);
        $stockId = (int) ($input['stock_id'] ?? 0);
        $qty = trim((string) ($input['qty'] ?? ''));
        $refCode = trim((string) ($input['ref_code'] ?? $input['refCode'] ?? ''));

        if ($productId <= 0 || $stockId <= 0 || $qty === '' || !is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'product_id, stock_id, qty > 0 обязательны'];
        }
        if ($refCode === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'ref_code обязателен'];
        }

        if ($this->products->findVisibleById($session, $productId) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }
        if ($this->stocks->findVisibleById($session, $stockId) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Узел не найден'];
        }

        $onHand = $this->balances->qtyForPair($tenantId, $productId, $stockId);
        $reserved = $this->reserves->sumActiveForPair($tenantId, $productId, $stockId);
        $available = bcsub($onHand, $reserved, 6);
        if (bccomp($available, $qty, 6) < 0) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'Недостаточно свободного остатка',
                'code' => 'insufficient_available',
                'qty_on_hand' => $onHand,
                'qty_reserved' => $reserved,
                'qty_available' => $available,
            ];
        }

        $id = $this->reserves->create(
            $tenantId,
            $productId,
            $stockId,
            $qty,
            $refCode,
            isset($input['note']) ? trim((string) $input['note']) : null,
            (int) $session['user_id']
        );

        return [
            'ok' => true,
            'status' => 201,
            'reserve' => $this->reserves->findActiveByIdInTenant($id, $tenantId)
                ?? ['id' => $id],
        ];
    }

    public function release(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $row = $this->reserves->findActiveByIdInTenant($id, $tenantId);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Резерв не найден или уже снят'];
        }

        if (!$this->reserves->release($id, $tenantId, (int) $session['user_id'])) {
            return ['ok' => false, 'status' => 409, 'error' => 'Не удалось снять резерв'];
        }

        return ['ok' => true, 'status' => 200, 'released' => true, 'id' => $id];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function productSummary(array $session, array $query): array
    {
        $productId = !empty($query['product_id']) ? (int) $query['product_id'] : null;

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->reserves->summaryByProduct($session, $productId),
        ];
    }
}
