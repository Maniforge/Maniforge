<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Wms\Security\WmsAccessGuard;
use App\Maniforge\Wms\Security\WmsService;

final class ScanController
{
    public function __construct(
        private readonly WmsService $wms = new WmsService(),
        private readonly WmsAccessGuard $access = new WmsAccessGuard(),
    ) {
    }

    public function resolve(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }
        $code = trim((string) ($ctx->input['code'] ?? $ctx->input['scan'] ?? ''));
        if ($code === '' && isset($_GET['code'])) {
            $code = trim((string) $_GET['code']);
        }
        $result = $this->wms->scan($session, $code);
        JsonResponse::send($result, (int) $result['status']);
    }

    public function postMovement(RequestContext $ctx): void
    {
        $session = $this->access->guardWrite($ctx);
        if ($session === null) {
            return;
        }
        $result = $this->wms->postMovementByScan($session, $ctx->input);
        JsonResponse::send($result, (int) $result['status']);
    }
}
