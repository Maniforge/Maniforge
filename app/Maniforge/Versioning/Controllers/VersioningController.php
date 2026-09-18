<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Controllers;

use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;
use App\Maniforge\Versioning\Repository\VersioningRepository;
use App\Maniforge\Versioning\Security\VersioningAccess;

final class VersioningController
{
    public function __construct(
        private readonly VersioningRepository $versions = new VersioningRepository(),
        private readonly VersioningAccess $access = new VersioningAccess(),
    ) {
    }

    public function health(): void
    {
        JsonResponse::send([
            'ok' => true,
            'service' => 'maniforge-versioning',
            'recording' => !in_array(
                strtolower(trim((string) ($_ENV['VERSIONING_ENABLED'] ?? 'true'))),
                ['0', 'false', 'off', 'no'],
                true
            ),
        ]);
    }

    public function listChanges(RequestContext $ctx): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }

        $query = $ctx->server['QUERY_STRING'] ?? '';
        parse_str(is_string($query) ? $query : '', $params);

        $filters = [
            'entity_table' => trim((string) ($params['entity_table'] ?? '')),
            'entity_id' => trim((string) ($params['entity_id'] ?? '')),
            'operation' => strtolower(trim((string) ($params['operation'] ?? ''))),
            'project_id' => isset($params['project_id']) ? (int) $params['project_id'] : null,
            'from' => trim((string) ($params['from'] ?? '')),
            'to' => trim((string) ($params['to'] ?? '')),
            'limit' => (int) ($params['limit'] ?? 50),
            'offset' => (int) ($params['offset'] ?? 0),
        ];

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $items = $this->versions->listInScope($tenantId, $subtenantId, $filters);
        $total = $this->versions->countInScope($tenantId, $subtenantId, $filters);

        JsonResponse::send([
            'ok' => true,
            'items' => $items,
            'total' => $total,
            'limit' => max(1, min(200, $filters['limit'])),
            'offset' => max(0, $filters['offset']),
        ]);
    }

    public function getChange(RequestContext $ctx, int $id): void
    {
        $session = $this->access->guardRead($ctx);
        if ($session === null) {
            return;
        }

        $change = $this->versions->findByIdInScope(
            $id,
            (string) $session['tenant_id'],
            (string) $session['subtenant_id']
        );
        if ($change === null) {
            JsonResponse::send(['ok' => false, 'error' => 'Запись истории не найдена'], 404);
            return;
        }

        JsonResponse::send(['ok' => true, 'change' => $change]);
    }

    public function listRegistry(RequestContext $ctx): void
    {
        $session = $this->access->guardRegistry($ctx);
        if ($session === null) {
            return;
        }

        JsonResponse::send([
            'ok' => true,
            'items' => $this->versions->listRegistry(true),
        ]);
    }
}
