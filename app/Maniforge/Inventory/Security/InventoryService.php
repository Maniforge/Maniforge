<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Security;

use App\Maniforge\Inventory\Repository\BalanceRepository;
use App\Maniforge\Inventory\Repository\MovementRepository;
use App\Maniforge\Inventory\Repository\SupplyChainOverviewRepository;
use App\Maniforge\Inventory\Security\LotService;
use App\Maniforge\Inventory\Security\OrderService;
use App\Maniforge\Inventory\Security\ReserveService;
use App\Maniforge\Rbac\Security\EntityDelegationShareService;
use App\Maniforge\Rbac\Security\RbacService;

final class InventoryService
{
    public function __construct(
        private readonly BalanceRepository $balances = new BalanceRepository(),
        private readonly MovementRepository $movements = new MovementRepository(),
        private readonly InventoryPostingService $posting = new InventoryPostingService(),
        private readonly EntityDelegationShareService $delegationShare = new EntityDelegationShareService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly ReserveService $reserves = new ReserveService(),
        private readonly SupplyChainOverviewRepository $overview = new SupplyChainOverviewRepository(),
        private readonly LotService $lots = new LotService(),
        private readonly OrderService $orders = new OrderService(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listReserves(array $session, array $query): array
    {
        return $this->reserves->list($session, $query);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createReserve(array $session, array $input): array
    {
        return $this->reserves->create($session, $input);
    }

    public function releaseReserve(array $session, int $id): array
    {
        return $this->reserves->release($session, $id);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function balancesSummary(array $session, array $query): array
    {
        return $this->reserves->productSummary($session, $query);
    }

    public function supplyChainOverview(array $session): array
    {
        return [
            'ok' => true,
            'status' => 200,
            'overview' => $this->overview->build($session),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, items?: list<array>}
     */
    public function listBalances(array $session, array $query): array
    {
        $filters = [];
        if (!empty($query['product_id'])) {
            $filters['product_id'] = (int) $query['product_id'];
        }
        if (!empty($query['stock_id'])) {
            $filters['stock_id'] = (int) $query['stock_id'];
        }
        if (filter_var($query['non_zero'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $filters['non_zero'] = true;
        }

        $rows = $this->balances->listVisible($session, $filters);
        foreach ($rows as $i => $row) {
            $rows[$i]['is_delegated_view'] =
                (string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id'];
        }

        return ['ok' => true, 'status' => 200, 'items' => $rows];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listMovements(array $session, array $query): array
    {
        $filters = ['limit' => isset($query['limit']) ? (int) $query['limit'] : 50];
        if (!empty($query['movement_type'])) {
            $filters['movement_type'] = (string) $query['movement_type'];
        }
        if (!empty($query['status'])) {
            $filters['status'] = (string) $query['status'];
        }

        $rows = $this->movements->listVisible($session, $filters);
        foreach ($rows as $i => $row) {
            $rows[$i]['is_delegated_view'] =
                (string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id'];
        }

        return ['ok' => true, 'status' => 200, 'items' => $rows];
    }

    public function getMovement(array $session, int $id): array
    {
        $row = $this->movements->findVisibleById($session, $id);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Движение не найдено'];
        }
        $row['is_delegated_view'] = (string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id'];

        return ['ok' => true, 'status' => 200, 'movement' => $row];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function postMovement(array $session, array $input): array
    {
        try {
            return $this->posting->postMovement($session, $input);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'insufficient_qty') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Недостаточно остатка',
                    'code' => 'insufficient_qty',
                ];
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function reverseMovement(array $session, int $id, array $input = []): array
    {
        try {
            return $this->posting->reverseMovement($session, $id, $input);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'insufficient_qty') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Недостаточно остатка для сторно',
                    'code' => 'insufficient_qty',
                ];
            }

            throw $e;
        }
    }

    public function postDraftMovement(array $session, int $id): array
    {
        try {
            return $this->posting->postDraftMovement($session, $id);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'insufficient_qty') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Недостаточно остатка',
                    'code' => 'insufficient_qty',
                ];
            }

            throw $e;
        }
    }

    public function cancelDraftMovement(array $session, int $id): array
    {
        return $this->posting->cancelDraftMovement($session, $id);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listLots(array $session, array $query): array
    {
        return $this->lots->list($session, $query);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function registerLot(array $session, array $input): array
    {
        return $this->lots->register($session, $input);
    }

    public function getLot(array $session, int $id): array
    {
        return $this->lots->get($session, $id);
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listOrders(array $session, array $query): array
    {
        return $this->orders->list($session, $query);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function createOrder(array $session, array $input): array
    {
        return $this->orders->create($session, $input);
    }

    public function getOrder(array $session, int $id): array
    {
        return $this->orders->get($session, $id);
    }

    public function confirmOrder(array $session, int $id): array
    {
        return $this->orders->confirm($session, $id);
    }

    public function fulfillOrder(array $session, int $id): array
    {
        return $this->orders->fulfill($session, $id);
    }

    public function cancelOrder(array $session, int $id): array
    {
        return $this->orders->cancel($session, $id);
    }

    public function listGrantPeers(array $session): array
    {
        if (!$this->rbac->hasAnyRole(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['super_admin', 'tenant_admin']
        )) {
            return ['ok' => false, 'status' => 403, 'error' => 'Требуется tenant_admin'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->delegationShare->listActiveGrantPeers((string) $session['tenant_id']),
        ];
    }
}
