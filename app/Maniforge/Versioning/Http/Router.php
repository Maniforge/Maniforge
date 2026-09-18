<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Http;

use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Versioning\Controllers\PageController;
use App\Maniforge\Versioning\Controllers\VersioningController;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $method = $ctx->method;
        $path = $this->normalizePath($ctx->path);
        $controller = new VersioningController();

        if ($method === 'GET' && ($path === '/' || $path === '/admin')) {
            (new PageController())->admin();
            return;
        }

        if ($method === 'GET' && $path === '/health') {
            $controller->health();
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/changes') {
            $controller->listChanges($ctx);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/changes/([0-9]+)$#', $path, $m) === 1) {
            $controller->getChange($ctx, (int) $m[1]);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/registry') {
            $controller->listRegistry($ctx);
            return;
        }

        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found'], JSON_UNESCAPED_UNICODE);
    }

    private function normalizePath(string $path): string
    {
        foreach (['/versioning'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $path = substr($path, strlen($prefix)) ?: '/';
                break;
            }
        }

        return rtrim($path, '/') ?: '/';
    }
}
