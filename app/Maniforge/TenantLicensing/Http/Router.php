<?php
declare(strict_types=1);

namespace App\Maniforge\TenantLicensing\Http;

use App\Maniforge\TenantLicensing\Controllers\PageController;
use App\Maniforge\TenantLicensing\Controllers\TenantLicensingController;
use App\Maniforge\TenantLicensing\Support\JsonResponse;
use App\Maniforge\TenantLicensing\Support\RequestContext;

final class Router
{
    public function dispatch(RequestContext $ctx): void
    {
        $method = $ctx->method;
        $path = $this->normalizePath($ctx->path);
        $controller = new TenantLicensingController();

        if ($method === 'GET' && ($path === '/' || $path === '/admin')) {
            (new PageController())->admin();
            return;
        }

        if ($method === 'GET' && $path === '/api-docs') {
            (new PageController())->apiDocs();
            return;
        }

        if ($method === 'GET' && $path === '/health') {
            JsonResponse::send(['ok' => true, 'service' => 'tenant-licensing']);
            return;
        }

        if ($method === 'POST' && $path === '/admin/tenants') {
            $controller->adminCreateTenant($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/tenants/update') {
            $controller->adminUpdateTenant($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/subtenants') {
            $controller->adminCreateSubtenant($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/subtenants/update') {
            $controller->adminUpdateSubtenant($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/plans') {
            $controller->adminUpsertPlan($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/licenses/assign') {
            $controller->adminAssignLicense($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/licenses/update') {
            $controller->adminUpdateLicense($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/admin/licenses/revoke') {
            $controller->adminRevokeLicense($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/tenants') {
            $controller->tenants($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/tenants') {
            $controller->createTenant($ctx);
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/tenants/([^/]+)$#', $path, $m) === 1) {
            $controller->updateTenant($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/tenants/([^/]+)/managed-tenants$#', $path, $m) === 1) {
            $controller->managedTenants($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/tenants/([^/]+)/managed-tenants$#', $path, $m) === 1) {
            $controller->createManagedTenant($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/v1/tenants/([^/]+)/managed-tenants/([^/]+)$#', $path, $m) === 1) {
            $controller->revokeManagedTenant($ctx, rawurldecode($m[1]), rawurldecode($m[2]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/tenants/([^/]+)/managed-tenants/([^/]+)/revoke$#', $path, $m) === 1) {
            $controller->revokeManagedTenant($ctx, rawurldecode($m[1]), rawurldecode($m[2]));
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/tenants/([^/]+)/subtenants$#', $path, $m) === 1) {
            $controller->subtenants($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/v1/tenants/([^/]+)/subtenants$#', $path, $m) === 1) {
            $controller->createSubtenant($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/tenants/([^/]+)/subtenants/([^/]+)$#', $path, $m) === 1) {
            $controller->updateSubtenant($ctx, rawurldecode($m[1]), rawurldecode($m[2]));
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/plans') {
            $controller->plans($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/plans') {
            $controller->upsertPlan($ctx);
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/plans/([^/]+)$#', $path, $m) === 1) {
            $controller->upsertPlan($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/licenses') {
            $controller->licenses($ctx);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/licenses/assign') {
            $controller->assignLicense($ctx);
            return;
        }

        if ($method === 'PATCH' && preg_match('#^/api/v1/licenses/([0-9]+)$#', $path, $m) === 1) {
            $controller->updateLicense($ctx, (int) $m[1]);
            return;
        }

        if ($method === 'POST' && $path === '/api/v1/licenses/revoke') {
            $controller->revokeLicense($ctx);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/tenants/([^/]+)/entitlements$#', $path, $m) === 1) {
            $controller->entitlements($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/v1/tenants/([^/]+)/quota$#', $path, $m) === 1) {
            $controller->quota($ctx, rawurldecode($m[1]));
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/ops/summary') {
            $controller->platformOpsSummary($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/audit') {
            $controller->audit($ctx);
            return;
        }

        if ($method === 'GET' && $path === '/api/v1/events') {
            $controller->events($ctx);
            return;
        }

        if ($method === 'GET' && preg_match('#^/internal/v1/tenants/([^/]+)/subtenants/([^/]+)/access-state$#', $path, $m) === 1) {
            $controller->accessState($ctx, rawurldecode($m[1]), rawurldecode($m[2]));
            return;
        }

        if ($method === 'GET' && $path === '/internal/v1/events/pending') {
            $controller->pendingEvents($ctx);
            return;
        }

        if ($method === 'POST' && preg_match('#^/internal/v1/events/([0-9]+)/ack$#', $path, $m) === 1) {
            $controller->ackEvent($ctx, (int) $m[1]);
            return;
        }

        JsonResponse::send([
            'ok' => false,
            'error' => 'Маршрут не найден',
            'method' => $method,
            'path' => $path,
        ], 404);
    }

    public function normalizePath(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $bases = ['/tenant-licensing'];

        foreach ($bases as $base) {
            if ($path === $base || $path === $base . '/') {
                return '/';
            }

            if (str_starts_with($path, $base . '/')) {
                return '/' . ltrim(substr($path, strlen($base . '/')), '/');
            }
        }

        return $path;
    }
}
