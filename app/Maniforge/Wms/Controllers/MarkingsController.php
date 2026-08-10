<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Wms\Security\WmsAccessGuard;
use App\Maniforge\Wms\Security\WmsService;

final class MarkingsController
{
    public function __construct(
        private readonly WmsService $wms = new WmsService(),
        private readonly WmsAccessGuard $access = new WmsAccessGuard(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->listMarkings($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->getMarking($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function trace(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->traceMarking($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function register(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->registerMarking($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function bulkRegister(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->bulkRegisterMarkings($session, $ctx->input);
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
