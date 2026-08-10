<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Security;

use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Security\EntityDelegationShareService;
use App\Maniforge\Rbac\Security\EntityMetaTypes;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;
use App\Maniforge\Warehouses\Repository\StockRepository;
use App\Maniforge\Warehouses\Repository\StockTypeRepository;
use App\Maniforge\Warehouses\Repository\WarehouseAuditRepository;
use App\Maniforge\Warehouses\Support\StockActorEnricher;
use App\Maniforge\Warehouses\Support\StockTypeCatalog;
use App\Maniforge\Warehouses\Support\WarehouseAudit;

final class StockService
{
    public function __construct(
        private readonly StockRepository $stocks = new StockRepository(),
        private readonly StockTypeRepository $types = new StockTypeRepository(),
        private readonly EntityMetaRepository $entityMeta = new EntityMetaRepository(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly WarehouseAudit $warehouseAudit = new WarehouseAudit(),
        private readonly StockActorEnricher $actors = new StockActorEnricher(),
        private readonly WarehouseAuditRepository $auditTrail = new WarehouseAuditRepository(),
        private readonly EntityScopeResolver $scopeResolver = new EntityScopeResolver(),
        private readonly EntityDelegationShareService $delegationShare = new EntityDelegationShareService(),
        private readonly RbacService $rbac = new RbacService(),
    ) {
    }

    /**
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function listTypes(): array
    {
        return ['ok' => true, 'status' => 200, 'items' => $this->types->listActive()];
    }

    /**
     * Peer-tenant по активным grant для админки (галочки родитель ↔ клиент).
     *
     * @return array{ok: bool, status: int, items?: list<string>, error?: string}
     */
    public function listGrantPeers(array $session): array
    {
        if (!$this->canConfigureDelegationShare($session)) {
            return ['ok' => false, 'status' => 403, 'error' => 'Требуется tenant_admin'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->delegationShare->listActiveGrantPeers((string) $session['tenant_id']),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function listStocks(array $session, array $query): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $filters = [
            'type' => trim((string) ($query['type'] ?? '')),
            'search' => trim((string) ($query['search'] ?? '')),
            'roots_only' => filter_var($query['roots_only'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'status' => trim((string) ($query['status'] ?? 'active')) ?: 'active',
        ];
        if ($filters['type'] === '') {
            unset($filters['type']);
        }
        if ($filters['search'] === '') {
            unset($filters['search']);
        }
        if (isset($query['parent_id'])) {
            $filters['parent_id'] = $query['parent_id'] === '' || $query['parent_id'] === null
                ? null
                : (int) $query['parent_id'];
        }

        $rows = $this->stocks->listVisible($session, $filters);
        $items = $this->enrichStocks($rows, $tenantId, $subtenantId);

        return ['ok' => true, 'status' => 200, 'items' => $items];
    }

    /**
     * @return array{ok: bool, status: int, tree?: list<array>, flat_count?: int, error?: string}
     */
    public function tree(array $session, array $query): array
    {
        $list = $this->listStocks($session, array_merge($query, ['status' => $query['status'] ?? 'active']));
        if (($list['ok'] ?? false) !== true) {
            return $list;
        }

        $items = $list['items'] ?? [];
        $byParent = [];
        foreach ($items as $item) {
            $pid = $item['parent_id'] ?? null;
            $key = $pid === null ? 0 : (int) $pid;
            $byParent[$key][] = $item;
        }

        $build = function (int $parentKey) use (&$build, &$byParent): array {
            $nodes = [];
            foreach ($byParent[$parentKey] ?? [] as $row) {
                $id = (int) $row['id'];
                $row['children'] = $build($id);
                $nodes[] = $row;
            }

            return $nodes;
        };

        return [
            'ok' => true,
            'status' => 200,
            'tree' => $build(0),
            'flat_count' => count($items),
        ];
    }

    public function getStock(array $session, int $id): array
    {
        $row = $this->stocks->findVisibleById($session, $id);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден'];
        }

        $row['path'] = $this->buildPath($session, $row);
        $row['children_count'] = $this->stocks->countChildren($id, (string) $session['tenant_id']);

        return ['ok' => true, 'status' => 200, 'stock' => $this->enrichStock($row, (string) $session['tenant_id'], (string) $session['subtenant_id'])];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function stockAudit(array $session, int $id, array $query): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        if ($this->stocks->findVisibleById($session, $id) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден'];
        }

        $limit = isset($query['limit']) ? (int) $query['limit'] : 50;

        return [
            'ok' => true,
            'status' => 200,
            'stock_id' => $id,
            'items' => $this->auditTrail->listForStock($tenantId, $subtenantId, $id, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, stock?: array, error?: string, code?: string}
     */
    public function createStock(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $name = trim((string) ($input['name'] ?? ''));
        $type = trim((string) ($input['type'] ?? ''));
        $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' && $input['parent_id'] !== null
            ? (int) $input['parent_id']
            : null;

        if ($name === '' || $type === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'name и type обязательны'];
        }

        if ($this->types->findByCode($type) === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Неизвестный тип узла', 'code' => 'unknown_stock_type'];
        }

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->stocks->findVisibleById($session, $parentId);
            if ($parent === null) {
                return ['ok' => false, 'status' => 404, 'error' => 'Родительский узел не найден'];
            }
            if (!$this->types->canBeChildOf($type, (string) $parent['type'])) {
                return [
                    'ok' => false,
                    'status' => 422,
                    'error' => "Тип {$type} не может быть дочерним для {$parent['type']}",
                    'code' => 'invalid_parent_type',
                ];
            }
        } elseif (!$this->types->canBeChildOf($type, null)) {
            return ['ok' => false, 'status' => 422, 'error' => 'Для этого типа требуется parent_id', 'code' => 'parent_required'];
        }

        if ($parent !== null) {
            $scopeRow = [
                'tenant_id' => (string) $parent['tenant_id'],
                'subtenant_id' => (string) $parent['subtenant_id'],
                'project_id' => $parent['project_id'] ?? null,
                'scope_visibility' => (string) $parent['scope_visibility'],
                'shared_subtenant_ids' => $parent['shared_subtenant_ids'] ?? null,
            ];
        } else {
            $resolved = $this->scopeResolver->resolveStockWriteScope($session, $input);
            if (($resolved['ok'] ?? false) !== true) {
                return [
                    'ok' => false,
                    'status' => (int) ($resolved['status'] ?? 422),
                    'error' => (string) ($resolved['error'] ?? 'Некорректный scope'),
                ];
            }
            $scopeRow = $resolved;
        }

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateCode($type, $name);
        }
        $code = strtolower($code);

        if ($this->stocks->findByCodeInWriteScope($scopeRow, $code) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'code уже занят в scope', 'code' => 'code_exists'];
        }

        $data = $input['data'] ?? null;
        if ($data !== null && !is_array($data)) {
            return ['ok' => false, 'status' => 422, 'error' => 'data должен быть объектом'];
        }

        try {
            $stock = $this->stocks->create(
                $scopeRow,
                $code,
                $name,
                $type,
                $parentId,
                $data,
                (int) $session['user_id']
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Конфликт уникальности', 'code' => 'duplicate'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка создания узла'];
        }

        $stockId = (int) ($stock['id'] ?? 0);
        $this->recordVersion($session, 'insert', null, $stock);
        $this->warehouseAudit->stockCreated($session, $stockId, $code, $type, [
            'parent_id' => $parentId,
            'name' => $name,
        ]);

        if (isset($input['external']) && is_array($input['external'])) {
            $extType = trim((string) ($input['external']['type'] ?? ''));
            $extId = trim((string) ($input['external']['id'] ?? ''));
            if ($extType !== '' && $extId !== '') {
                $this->bindStockExternalMeta(
                    $extType,
                    $extId,
                    $stockId,
                    $tenantId,
                    (string) ($stock['subtenant_id'] ?? $subtenantId),
                    $stock
                );
            }
        }

        $shareResult = $this->applyDelegationShareFromInput($session, $stockId, $input, $tenantId);
        if ($shareResult !== null && ($shareResult['ok'] ?? false) !== true) {
            return $shareResult;
        }
        if ($shareResult !== null) {
            $stock = $this->stocks->findByIdInTenant($stockId, $tenantId) ?? $stock;
        }

        return [
            'ok' => true,
            'status' => 201,
            'stock' => $this->enrichStock($stock, $tenantId, $subtenantId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateStock(array $session, int $id, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $before = $this->stocks->findVisibleById($session, $id);
        if ($before === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден'];
        }

        if ((string) ($before['tenant_id'] ?? '') !== $tenantId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Изменение сущности другого tenant только в его контексте (switch-context)',
                'code' => 'delegated_entity_read_only',
            ];
        }

        if ($this->hasDelegationShareInput($input)) {
            $shareResult = $this->applyDelegationShareFromInput($session, $id, $input, $tenantId);
            if ($shareResult !== null && ($shareResult['ok'] ?? false) !== true) {
                return $shareResult;
            }
        }

        $fields = [];
        if (array_key_exists('name', $input)) {
            $fields['name'] = trim((string) $input['name']);
        }
        if (array_key_exists('active', $input)) {
            $fields['active'] = filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        if (array_key_exists('data', $input)) {
            if ($input['data'] !== null && !is_array($input['data'])) {
                return ['ok' => false, 'status' => 422, 'error' => 'data должен быть объектом'];
            }
            $fields['data'] = $input['data'];
        }

        if (array_key_exists('parent_id', $input)) {
            $newParentId = $input['parent_id'] === null || $input['parent_id'] === ''
                ? null
                : (int) $input['parent_id'];
            if ($newParentId === $id) {
                return ['ok' => false, 'status' => 422, 'error' => 'Узел не может быть родителем самому себе'];
            }
            $childType = (string) ($input['type'] ?? $before['type']);
            if ($newParentId !== null) {
                $parent = $this->stocks->findVisibleById($session, $newParentId);
                if ($parent === null) {
                    return ['ok' => false, 'status' => 404, 'error' => 'Родитель не найден'];
                }
                if ($this->isDescendant($session, $id, $newParentId)) {
                    return ['ok' => false, 'status' => 422, 'error' => 'Нельзя переместить узел под своего потомка', 'code' => 'cycle'];
                }
                if (!$this->types->canBeChildOf($childType, (string) $parent['type'])) {
                    return ['ok' => false, 'status' => 422, 'error' => 'Несовместимый тип родителя', 'code' => 'invalid_parent_type'];
                }
            } elseif (!$this->types->canBeChildOf($childType, null)) {
                return ['ok' => false, 'status' => 422, 'error' => 'Для типа требуется родитель'];
            }
            $fields['parent_id'] = $newParentId;
        }

        if (isset($input['type'])) {
            $newType = trim((string) $input['type']);
            if ($this->types->findByCode($newType) === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'Неизвестный тип'];
            }
            $fields['type'] = $newType;
        }

        if ($fields === []) {
            $after = $this->hasDelegationShareInput($input)
                ? ($this->stocks->findVisibleById($session, $id) ?? $before)
                : $before;

            return ['ok' => true, 'status' => 200, 'stock' => $this->enrichStock($after, $tenantId, $subtenantId)];
        }

        $this->stocks->update($id, $tenantId, $fields, (int) $session['user_id']);
        $after = $this->stocks->findVisibleById($session, $id);
        $this->recordVersion($session, 'update', $before, $after);
        $diff = WarehouseAudit::diff($before, $after ?? $before, array_keys($fields));
        if ($diff !== []) {
            $this->warehouseAudit->stockUpdated($session, $id, $diff, ['code' => (string) ($before['code'] ?? '')]);
        }

        return ['ok' => true, 'status' => 200, 'stock' => $this->enrichStock($after ?? $before, $tenantId, $subtenantId)];
    }

    public function archiveStock(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $before = $this->stocks->findVisibleById($session, $id);
        if ($before === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден'];
        }

        if ($this->stocks->countChildren($id, $tenantId, true) > 0) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'Сначала архивируйте дочерние узлы',
                'code' => 'has_active_children',
            ];
        }

        $this->stocks->update($id, $tenantId, [
            'status' => 'archived',
            'active' => 0,
        ], (int) $session['user_id']);

        $after = $this->stocks->findVisibleById($session, $id);
        $this->recordVersion($session, 'delete', $before, $after);
        $this->warehouseAudit->stockArchived($session, $id, (string) ($before['code'] ?? ''));

        return ['ok' => true, 'status' => 200, 'stock' => $this->enrichStock($after ?? $before, $tenantId, $subtenantId)];
    }

    /**
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function children(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        if ($this->stocks->findVisibleById($session, $id) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Узел не найден'];
        }

        $rows = $this->stocks->listVisible($session, ['parent_id' => $id, 'status' => 'active']);
        $items = $this->enrichStocks($rows, $tenantId, $subtenantId);

        return ['ok' => true, 'status' => 200, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function bindExternal(array $session, int $id, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $stock = $this->stocks->findVisibleById($session, $id);
        if ($stock === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Узел не найден'];
        }

        $extType = trim((string) ($input['type'] ?? ''));
        $extId = trim((string) ($input['external_id'] ?? $input['meta'] ?? ''));
        if ($extType === '' || $extId === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'type и external_id обязательны'];
        }

        $this->bindStockExternalMeta(
            $extType,
            $extId,
            $id,
            $tenantId,
            (string) ($stock['subtenant_id'] ?? $subtenantId),
            $stock
        );
        $this->warehouseAudit->stockExternalBound($session, $id, $extType, $extId);

        return ['ok' => true, 'status' => 200, 'stock_id' => $id, 'external_type' => $extType, 'external_id' => $extId];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichStocks(array $rows, string $tenantId, string $subtenantId): array
    {
        $labels = StockTypeCatalog::labels();
        foreach ($rows as $i => $row) {
            $rows[$i]['type_label'] = $labels[$row['type'] ?? ''] ?? ucfirst((string) ($row['type'] ?? ''));
            $rows[$i]['is_delegated_view'] = (string) ($row['tenant_id'] ?? '') !== $tenantId;
        }

        return $this->actors->enrichMany($rows, $tenantId, $subtenantId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, error?: string, code?: string}|null
     */
    private function applyDelegationShareFromInput(array $session, int $stockId, array $input, string $ownerTenantId): ?array
    {
        if (!$this->hasDelegationShareInput($input)) {
            return null;
        }

        if (!$this->canConfigureDelegationShare($session)) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Доступ grant principal↔managed настраивает tenant_admin',
                'code' => 'delegation_share_forbidden',
            ];
        }

        $resolved = $this->delegationShare->resolveForOwner($ownerTenantId, $input);
        if (($resolved['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($resolved['status'] ?? 422),
                'error' => (string) ($resolved['error'] ?? 'Некорректный delegation share'),
            ];
        }

        $this->stocks->update($stockId, $ownerTenantId, [
            'shared_grant_tenant_ids_json' => $this->delegationShare->encodeJson($resolved['tenant_ids'] ?? null),
        ], (int) $session['user_id']);

        return ['ok' => true, 'status' => 200];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasDelegationShareInput(array $input): bool
    {
        foreach ([
            'delegation_share_tenant_ids',
            'delegationShareTenantIds',
            'shared_grant_tenant_ids',
            'sharedGrantTenantIds',
            'share_with_principal',
            'shareWithPrincipal',
            'share_with_managed',
            'shareWithManaged',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                return true;
            }
        }

        return false;
    }

    private function canConfigureDelegationShare(array $session): bool
    {
        return $this->rbac->hasAnyRole(
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            ['super_admin', 'tenant_admin']
        );
    }

    /**
     * @param array<string, mixed> $stock
     */
    private function bindStockExternalMeta(
        string $extType,
        string $extId,
        int $stockId,
        string $tenantId,
        string $subtenantId,
        array $stock,
    ): void {
        $oIndex = null;
        $oRef = (string) $stockId;
        if (isset($stock['project_id']) && $stock['project_id'] !== null) {
            $oIndex = EntityMetaTypes::I_PROJECT;
            $oRef = (string) $stock['project_id'];
        }

        $this->entityMeta->bind(
            $extType,
            $extId,
            EntityMetaTypes::I_STOCK,
            $stockId,
            $tenantId,
            $subtenantId,
            $oIndex,
            $oRef
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichStock(array $row, string $tenantId, string $subtenantId): array
    {
        return $this->enrichStocks([$row], $tenantId, $subtenantId)[0] ?? $row;
    }

    private function generateCode(string $type, string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'node';
        }
        if (strlen($slug) > 40) {
            $slug = substr($slug, 0, 40);
        }

        return $type . '-' . $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    private function isDescendant(array $session, int $ancestorId, int $candidateParentId): bool
    {
        $descendants = $this->stocks->listDescendantIds($session, $ancestorId);

        return in_array($candidateParentId, $descendants, true);
    }

    /**
     * @param array<string, mixed> $node
     * @return list<array<string, mixed>>
     */
    private function buildPath(array $session, array $node): array
    {
        $path = [['id' => $node['id'], 'code' => $node['code'], 'name' => $node['name']]];
        $parentId = $node['parent_id'] ?? null;
        $guard = 0;
        while ($parentId !== null && $guard < 32) {
            $guard++;
            $parent = $this->stocks->findVisibleById($session, (int) $parentId);
            if ($parent === null) {
                break;
            }
            array_unshift($path, ['id' => $parent['id'], 'code' => $parent['code'], 'name' => $parent['name']]);
            $parentId = $parent['parent_id'] ?? null;
        }

        return $path;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function recordVersion(array $session, string $op, ?array $before, ?array $after): void
    {
        $row = $after ?? $before;
        if ($row === null) {
            return;
        }

        $projectId = isset($row['project_id']) && $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $this->versioning->record(
            VersioningScope::fromSession($session, $projectId),
            'maniforge_wh_stocks',
            (string) ($row['id'] ?? ''),
            $op,
            $before,
            $after,
            (string) ($row['code'] ?? '')
        );
    }
}
