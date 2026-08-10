<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Security;

use App\Maniforge\Inventory\Repository\OrderRepository;
use App\Maniforge\Inventory\Repository\ReserveRepository;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Warehouses\Repository\StockRepository;

final class OrderService
{
    public function __construct(
        private readonly OrderRepository $orders = new OrderRepository(),
        private readonly ReserveRepository $reserves = new ReserveRepository(),
        private readonly ReserveService $reserveService = new ReserveService(),
        private readonly InventoryPostingService $posting = new InventoryPostingService(),
        private readonly StockRepository $stocks = new StockRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function list(array $session, array $query): array
    {
        $filters = [];
        foreach (['status', 'limit'] as $key) {
            if (!empty($query[$key])) {
                $filters[$key] = $query[$key];
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->orders->listInTenant((string) $session['tenant_id'], $filters),
        ];
    }

    public function get(array $session, int $id): array
    {
        $order = $this->orders->findByIdInTenant($id, (string) $session['tenant_id']);
        if ($order === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Заказ не найден'];
        }

        return ['ok' => true, 'status' => 200, 'order' => $order];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function create(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $stockId = (int) ($input['stock_id'] ?? 0);
        $orderNumber = trim((string) ($input['order_number'] ?? $input['orderNumber'] ?? ''));
        if ($orderNumber === '') {
            $orderNumber = 'ord-' . gmdate('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }

        if ($stockId <= 0 || !$this->orders->stockVisible($session, $stockId)) {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден'];
        }

        $lines = $this->parseLines($input['lines'] ?? []);
        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'lines обязателен (product_id, qty)'];
        }
        if (!$this->orders->validateLinesVisible($session, $lines)) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар в строке не найден'];
        }

        try {
            $id = $this->orders->create(
                $tenantId,
                $orderNumber,
                $stockId,
                isset($input['note']) ? trim((string) $input['note']) : null,
                is_array($input['metadata'] ?? null) ? $input['metadata'] : null,
                (int) $session['user_id'],
                $lines
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'order_number уже существует', 'code' => 'duplicate'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка создания заказа'];
        }

        return [
            'ok' => true,
            'status' => 201,
            'order' => $this->orders->findByIdInTenant($id, $tenantId),
        ];
    }

    public function confirm(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $order = $this->orders->findByIdInTenant($id, $tenantId);
        if ($order === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Заказ не найден'];
        }
        if ((string) ($order['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'status' => 422, 'error' => 'Только draft можно подтвердить', 'code' => 'invalid_status'];
        }

        $refCode = $this->orders->orderRefCode($order);
        $stockId = (int) ($order['stock_id'] ?? 0);
        foreach ($order['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $res = $this->reserveService->create($session, [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'stock_id' => $stockId,
                'qty' => (string) ($line['qty_ordered'] ?? '0'),
                'ref_code' => $refCode,
                'note' => 'order #' . ($order['order_number'] ?? ''),
            ]);
            if (($res['ok'] ?? false) !== true) {
                return $res;
            }
        }

        if (!$this->orders->updateStatus($id, $tenantId, 'confirmed', 'confirmed_at')) {
            return ['ok' => false, 'status' => 500, 'error' => 'Не удалось обновить статус'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'order' => $this->orders->findByIdInTenant($id, $tenantId),
        ];
    }

    public function fulfill(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $order = $this->orders->findByIdInTenant($id, $tenantId);
        if ($order === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Заказ не найден'];
        }
        if ((string) ($order['status'] ?? '') !== 'confirmed') {
            return ['ok' => false, 'status' => 422, 'error' => 'Только confirmed можно отгрузить', 'code' => 'invalid_status'];
        }

        $stockId = (int) ($order['stock_id'] ?? 0);
        $stock = $this->stocks->findVisibleById($session, $stockId);
        if ($stock === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Склад не найден'];
        }

        $issueLines = [];
        foreach ($order['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $qty = (string) ($line['qty_ordered'] ?? '0');
            $issueLines[] = [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'stock_id' => $stockId,
                'qty' => $qty,
            ];
        }

        $this->reserves->releaseActiveByRefCode($tenantId, $this->orders->orderRefCode($order), (int) $session['user_id']);

        $posted = $this->posting->postMovement($session, [
            'movement_type' => MovementTypes::ISSUE,
            'stock_id' => $stockId,
            'subtenant_id' => $stock['subtenant_id'] ?? $session['subtenant_id'] ?? 'main',
            'doc_number' => 'fulfill-' . ($order['order_number'] ?? $id),
            'lines' => $issueLines,
            'metadata' => ['order_id' => $id, 'order_number' => $order['order_number'] ?? ''],
        ]);
        if (($posted['ok'] ?? false) !== true) {
            return $posted;
        }

        if (!$this->orders->updateStatus($id, $tenantId, 'fulfilled', 'fulfilled_at')) {
            return ['ok' => false, 'status' => 500, 'error' => 'Движение проведено, но статус заказа не обновлён'];
        }

        $order = $this->orders->findByIdInTenant($id, $tenantId);
        if (is_array($order)) {
            $order['fulfillment_movement_id'] = (int) (($posted['movement']['id'] ?? 0));
        }

        return ['ok' => true, 'status' => 200, 'order' => $order, 'movement' => $posted['movement'] ?? null];
    }

    public function cancel(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $order = $this->orders->findByIdInTenant($id, $tenantId);
        if ($order === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Заказ не найден'];
        }

        $status = (string) ($order['status'] ?? '');
        if (!in_array($status, ['draft', 'confirmed'], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Заказ нельзя отменить', 'code' => 'invalid_status'];
        }

        if ($status === 'confirmed') {
            $this->reserves->releaseActiveByRefCode($tenantId, $this->orders->orderRefCode($order), (int) $session['user_id']);
        }

        if (!$this->orders->updateStatus($id, $tenantId, 'cancelled', 'cancelled_at')) {
            return ['ok' => false, 'status' => 500, 'error' => 'Не удалось отменить'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'order' => $this->orders->findByIdInTenant($id, $tenantId),
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{product_id: int, qty_ordered: string}>
     */
    private function parseLines(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            $qty = trim((string) ($row['qty'] ?? $row['qty_ordered'] ?? ''));
            if ($productId <= 0 || $qty === '' || !is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
                continue;
            }
            $out[] = ['product_id' => $productId, 'qty_ordered' => $qty];
        }

        return $out;
    }
}
