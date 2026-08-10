<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Security;

use App\Maniforge\Inventory\Repository\LotRepository;
use App\Maniforge\Products\Repository\ProductRepository;

final class LotService
{
    public function __construct(
        private readonly LotRepository $lots = new LotRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $session, array $query): array
    {
        $filters = [];
        foreach (['product_id', 'batch_code', 'status'] as $key) {
            if (!empty($query[$key])) {
                $filters[$key] = $query[$key];
            }
        }

        return ['ok' => true, 'status' => 200, 'items' => $this->lots->listInTenant($session, $filters)];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function register(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $productId = (int) ($input['product_id'] ?? 0);
        $batchCode = trim((string) ($input['batch_code'] ?? ''));
        $lotCode = trim((string) ($input['lot_code'] ?? ''));

        if ($productId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'product_id обязателен'];
        }
        if ($this->products->findVisibleById($session, $productId) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }

        $existing = $this->lots->findByKey($tenantId, $productId, $batchCode, $lotCode);
        if ($existing !== null) {
            return ['ok' => true, 'status' => 200, 'lot' => $existing, 'created' => false];
        }

        try {
            $id = $this->lots->create(
                $tenantId,
                $productId,
                $batchCode,
                $lotCode,
                isset($input['manufactured_at']) ? trim((string) $input['manufactured_at']) : null,
                isset($input['expires_at']) ? trim((string) $input['expires_at']) : null,
                isset($input['note']) ? trim((string) $input['note']) : null,
                (int) $session['user_id']
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $existing = $this->lots->findByKey($tenantId, $productId, $batchCode, $lotCode);
                if ($existing !== null) {
                    return ['ok' => true, 'status' => 200, 'lot' => $existing];
                }
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка регистрации партии'];
        }

        return [
            'ok' => true,
            'status' => 201,
            'created' => true,
            'lot' => $this->lots->findByIdInTenant($id, $tenantId),
        ];
    }

    public function get(array $session, int $id): array
    {
        $lot = $this->lots->findByIdInTenant($id, (string) $session['tenant_id']);
        if ($lot === null || $this->products->findVisibleById($session, (int) $lot['product_id']) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Партия не найдена'];
        }

        return ['ok' => true, 'status' => 200, 'lot' => $lot];
    }
}
