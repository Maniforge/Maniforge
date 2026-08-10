<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Wms\Security\WmsAccessGuard;
use App\Maniforge\Wms\Security\WmsService;

final class PacksController
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
        $result = $this->wms->listPacks($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function create(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->createPack($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->getPack($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function delete(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->deleteDraftPack($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function seal(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->sealPack($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function disaggregate(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->disaggregatePack($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function addMarking(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->addMarkingToPack($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function addChild(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->addChildPack($session, $id, $ctx->input);
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
