<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Controllers;

use App\Maniforge\Inventory\Security\InventoryAccess;
use App\Maniforge\Inventory\Security\InventoryService;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class MovementsController
{
    public function __construct(
        private readonly InventoryService $inventory = new InventoryService(),
        private readonly InventoryAccess $access = new InventoryAccess(),
    ) {
    }

    public function list(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->listMovements($session, $this->queryParams($ctx));
        JsonResponse::send($result, (int) $result['status']);
    }

    public function create(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->postMovement($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function get(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->getMovement($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function reverse(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->reverseMovement($session, $id, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function postDraft(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->postDraftMovement($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function cancelDraft(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->cancelDraftMovement($session, $id);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function grantPeers(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->inventory->listGrantPeers($session);
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
