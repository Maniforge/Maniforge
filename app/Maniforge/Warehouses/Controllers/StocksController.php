<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Warehouses\Security\StockService;
use App\Maniforge\Warehouses\Security\WarehouseAccess;

final class StocksController
{
    public function __construct(
        private readonly StockService $stocks = new StockService(),
        private readonly WarehouseAccess $access = new WarehouseAccess(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->listStocks($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function tree(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->tree($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function create(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->createStock($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->getStock($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function patch(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->updateStock($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function delete(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardDelete($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->archiveStock($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function children(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->children($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function bindExternal(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->bindExternal($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function audit(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardAuditRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->stockAudit($session, $id, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function grantPeers(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->stocks->listGrantPeers($session);
        JsonResponse::send($result, (int) $result['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(RequestContext $ctx): array
    {
        $query = $ctx->server['QUERY_STRING'] ?? '';
        parse_str(is_string($query) ? $query : '', $params);

        return is_array($params) ? $params : [];
    }
}
