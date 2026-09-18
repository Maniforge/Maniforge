<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Http;

use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Wms\Controllers\HealthController;
use App\Maniforge\Wms\Controllers\MarkingsController;
use App\Maniforge\Wms\Controllers\PacksController;
use App\Maniforge\Wms\Controllers\ScanController;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $path = $this->normalizePath($ctx->path);
        $packs = new PacksController();
        $markings = new MarkingsController();
        $scan = new ScanController();

        if ($ctx->method === 'GET' && $path === '/health') {
            (new HealthController())();
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/packs') {
            $packs->list($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/packs') {
            $packs->create($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/packs/([0-9]+)$#', $path, $m) === 1) {
            $packs->get($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'DELETE' && preg_match('#^/api/v1/packs/([0-9]+)$#', $path, $m) === 1) {
            $packs->delete($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/packs/([0-9]+)/seal$#', $path, $m) === 1) {
            $packs->seal($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/packs/([0-9]+)/disaggregate$#', $path, $m) === 1) {
            $packs->disaggregate($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/packs/([0-9]+)/markings$#', $path, $m) === 1) {
            $packs->addMarking($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && preg_match('#^/api/v1/packs/([0-9]+)/children$#', $path, $m) === 1) {
            $packs->addChild($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/markings') {
            $markings->list($ctx);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/markings/([0-9]+)/trace$#', $path, $m) === 1) {
            $markings->trace($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'GET' && preg_match('#^/api/v1/markings/([0-9]+)$#', $path, $m) === 1) {
            $markings->get($ctx, (int) $m[1]);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/markings') {
            $markings->register($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/markings/bulk') {
            $markings->bulkRegister($ctx);
            return;
        }

        if ($ctx->method === 'GET' && $path === '/api/v1/scan') {
            $scan->resolve($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/scan') {
            $scan->resolve($ctx);
            return;
        }

        if ($ctx->method === 'POST' && $path === '/api/v1/movements/scan') {
            $scan->postMovement($ctx);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }

    private function normalizePath(string $path): string
    {
        foreach (['/maniforge/wms', '/wms'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }
}
