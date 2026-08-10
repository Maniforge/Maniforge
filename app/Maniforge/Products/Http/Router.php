<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Http;

use App\Maniforge\Products\Controllers\HealthController;
use App\Maniforge\Products\Controllers\ProductsController;
use App\Maniforge\Rbac\Support\RequestContext;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $path = $this->normalizePath($ctx->path);
        $products = new ProductsController();

        if ($ctx->method === 'GET' && $path === '/health') {
            (new HealthController())();
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/delegation/grant-peers') {
            $products->grantPeers($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/products') {
            $products->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/products') {
            $products->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/products/by-barcode/(.+)$#', $path, $m) === 1) {
            $products->getByBarcode($ctx, (string) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/products/([0-9]+)$#', $path, $m) === 1) {
            $products->get($ctx, (int) $m[1]);
            return;
        }

        if (in_array($ctx->method, ['PATCH', 'PUT'], true) && preg_match('#^/api/v1/products/([0-9]+)$#', $path, $m) === 1) {
            $products->patch($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'DELETE' && preg_match('#^/api/v1/products/([0-9]+)$#', $path, $m) === 1) {
            $products->delete($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/products/([0-9]+)/external-meta$#', $path, $m) === 1) {
            $products->bindExternal($ctx, (int) $m[1]);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }

    private function normalizePath(string $path): string
    {
        foreach (['/maniforge/products', '/products'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }
}
