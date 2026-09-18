<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Http;

use App\Maniforge\Inventory\Controllers\BalancesController;
use App\Maniforge\Inventory\Controllers\HealthController;
use App\Maniforge\Inventory\Controllers\LotsController;
use App\Maniforge\Inventory\Controllers\MovementsController;
use App\Maniforge\Inventory\Controllers\OrdersController;
use App\Maniforge\Inventory\Controllers\ReportsController;
use App\Maniforge\Inventory\Controllers\ReservesController;
use App\Maniforge\Rbac\Support\RequestContext;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $path = $this->normalizePath($ctx->path);
        $balances = new BalancesController();
        $movements = new MovementsController();
        $reserves = new ReservesController();
        $reports = new ReportsController();
        $lots = new LotsController();
        $orders = new OrdersController();

        if ($ctx->method === 'GET' && $path === '/health') {
            (new HealthController())();
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/delegation/grant-peers') {
            $movements->grantPeers($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/balances') {
            $balances->list($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/balances/summary') {
            $reports->balancesSummary($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/reports/overview') {
            $reports->overview($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/reserves') {
            $reserves->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/reserves') {
            $reserves->create($ctx);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/reserves/([0-9]+)/release$#', $path, $m) === 1) {
            $reserves->release($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/movements') {
            $movements->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/movements') {
            $movements->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/movements/([0-9]+)$#', $path, $m) === 1) {
            $movements->get($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/movements/([0-9]+)/reverse$#', $path, $m) === 1) {
            $movements->reverse($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/movements/([0-9]+)/post$#', $path, $m) === 1) {
            $movements->postDraft($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'DELETE' && preg_match('#^/api/v1/movements/([0-9]+)$#', $path, $m) === 1) {
            $movements->cancelDraft($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/lots') {
            $lots->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/lots') {
            $lots->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/lots/([0-9]+)$#', $path, $m) === 1) {
            $lots->get($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/orders') {
            $orders->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/orders') {
            $orders->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/orders/([0-9]+)$#', $path, $m) === 1) {
            $orders->get($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/orders/([0-9]+)/confirm$#', $path, $m) === 1) {
            $orders->confirm($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/orders/([0-9]+)/fulfill$#', $path, $m) === 1) {
            $orders->fulfill($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/orders/([0-9]+)/cancel$#', $path, $m) === 1) {
            $orders->cancel($ctx, (int) $m[1]);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }

    private function normalizePath(string $path): string
    {
        foreach (['/maniforge/inventory', '/inventory'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }
}
