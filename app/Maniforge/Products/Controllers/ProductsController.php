<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Controllers;

use App\Maniforge\Products\Security\ProductAccess;
use App\Maniforge\Products\Security\ProductService;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class ProductsController
{
    public function __construct(
        private readonly ProductService $products = new ProductService(),
        private readonly ProductAccess $access = new ProductAccess(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->listProducts($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function create(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->createProduct($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function getByBarcode(RequestContext $ctx, string $barcode): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->getProductByBarcode($session, rawurldecode($barcode));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->getProduct($session, $id, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function patch(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->updateProduct($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function delete(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardDelete($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->archiveProduct($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function bindExternal(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->bindExternal($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function grantPeers(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->products->listGrantPeers($session);
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
