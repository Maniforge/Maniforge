<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Http;

use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Warehouses\Controllers\HealthController;
use App\Maniforge\Warehouses\Controllers\StocksController;
use App\Maniforge\Warehouses\Controllers\StockTypesController;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $path = $this->normalizePath($ctx->path);
        $stocks = new StocksController();
        $types = new StockTypesController();

        if ($ctx->method === 'GET' && $path === '/health') {
            (new HealthController())();
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/stock-types') {
            $types->list($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/stocks') {
            $stocks->list($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/stocks/tree') {
            $stocks->tree($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/delegation/grant-peers') {
            $stocks->grantPeers($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/stocks') {
            $stocks->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/stocks/([0-9]+)$#', $path, $m) === 1) {
            $stocks->get($ctx, (int) $m[1]);
            return;
        }

        if (in_array($ctx->method, ['PATCH', 'PUT'], true) && preg_match('#^/api/v1/stocks/([0-9]+)$#', $path, $m) === 1) {
            $stocks->patch($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'DELETE' && preg_match('#^/api/v1/stocks/([0-9]+)$#', $path, $m) === 1) {
            $stocks->delete($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/stocks/([0-9]+)/children$#', $path, $m) === 1) {
            $stocks->children($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/stocks/([0-9]+)/external-meta$#', $path, $m) === 1) {
            $stocks->bindExternal($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/stocks/([0-9]+)/audit$#', $path, $m) === 1) {
            $stocks->audit($ctx, (int) $m[1]);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }

    private function normalizePath(string $path): string
    {
        foreach (['/maniforge/warehouses', '/warehouses'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }
}
