<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Warehouses\Security\StockService;
use App\Maniforge\Warehouses\Security\WarehouseAccess;

final class StockTypesController
{
    public function __construct(
        private readonly StockService $stocks = new StockService(),
        private readonly WarehouseAccess $access = new WarehouseAccess(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->access->guardTypes($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->listTypes();
        JsonResponse::send($result, (int) $result['status']);
    }
}
