<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Controllers;

use App\Maniforge\Inventory\Security\InventoryAccess;
use App\Maniforge\Inventory\Security\InventoryService;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class ReportsController
{
    public function __construct(
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly InventoryAccess $access = new InventoryAccess(),
    ) {
    }

    public function overview(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->supplyChainOverview($session);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function balancesSummary(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $query = $ctx->server['QUERY_STRING'] ?? '';
        parse_str(is_string($query) ? $query : '', $params);
        $result = $this->inventory->balancesSummary($session, is_array($params) ? $params : []);
        JsonResponse::send($result, (int) $result['status']);
    }
}
