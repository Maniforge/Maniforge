<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Security;

use App\Maniforge\Inventory\Repository\BalanceRepository;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Products\Support\Ean13;
use App\Maniforge\Products\Support\ProductActorEnricher;
use App\Maniforge\Products\Support\ProductAudit;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Security\EntityDelegationShareService;
use App\Maniforge\Rbac\Security\EntityMetaTypes;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;

final class ProductService
{
    public function __construct(
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly EntityMetaRepository $entityMeta = new EntityMetaRepository(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly ProductAudit $audit = new ProductAudit(),
        private readonly ProductActorEnricher $actors = new ProductActorEnricher(),
        private readonly EntityScopeResolver $scopeResolver = new EntityScopeResolver(),
        private readonly EntityDelegationShareService $delegationShare = new EntityDelegationShareService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly BalanceRepository $balances = new BalanceRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{ok: bool, status: int, items?: list<array>, error?: string}
     */
    public function listProducts(array $session, array $query): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $filters = [
            'search' => trim((string) ($query['search'] ?? '')),
            'status' => trim((string) ($query['status'] ?? 'active')) ?: 'active',
        ];
        if ($filters['search'] === '') {
            unset($filters['search']);
        }

        $rows = $this->products->listVisible($session, $filters);

        return [
            'ok' => true,
            'status' => 200,
            'items' => $this->enrichProducts($rows, $tenantId, $subtenantId),
        ];
    }

    public function getProductByBarcode(array $session, string $rawBarcode): array
    {
        $parsed = Ean13::normalize($rawBarcode);
        if (($parsed['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => 422, 'error' => (string) ($parsed['error'] ?? 'Неверный EAN-13')];
        }

        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $product = $this->products->findVisibleByEan13($session, (string) $parsed['ean13']);
        if ($product === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар с таким EAN-13 не найден'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'kind' => 'product',
            'barcode_type' => 'ean13',
            'barcode' => (string) $parsed['ean13'],
            'product' => $this->enrichProduct($product, $tenantId, $subtenantId),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function getProduct(array $session, int $id, array $query = []): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $row = $this->products->findVisibleById($session, $id);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }

        $product = $this->enrichProduct($row, $tenantId, $subtenantId);
        $include = array_map('trim', explode(',', strtolower((string) ($query['include'] ?? ''))));
        if (in_array('balances', $include, true)) {
            $product['balances'] = $this->balances->listVisible($session, [
                'product_id' => $id,
                'non_zero' => false,
            ]);
        }

        return [
            'ok' => true,
            'status' => 200,
            'product' => $product,
        ];
    }

    /**
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
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, product?: array, error?: string, code?: string}
     */
    public function createProduct(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'name обязателен'];
        }

        $resolved = $this->scopeResolver->resolveStockWriteScope($session, $input);
        if (($resolved['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($resolved['status'] ?? 422),
                'error' => (string) ($resolved['error'] ?? 'Некорректный scope'),
            ];
        }
        $scopeRow = $resolved;

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $code = $this->generateCode($name);
        }
        $code = strtolower($code);

        if ($this->products->findByCodeInWriteScope($scopeRow, $code) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'code уже занят в scope', 'code' => 'code_exists'];
        }

        $eanParsed = $this->parseEan13FromInput($input);
        if (($eanParsed['ok'] ?? false) !== true) {
            return ['ok' => false, 'status' => 422, 'error' => (string) ($eanParsed['error'] ?? 'EAN-13')];
        }
        $barcodeEan13 = $eanParsed['ean13'] ?? null;
        if ($barcodeEan13 !== null && $this->products->findByEan13InTenant($tenantId, $barcodeEan13) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'EAN-13 уже привязан к товару', 'code' => 'ean13_exists'];
        }

        $unit = trim((string) ($input['unit'] ?? 'pcs'));
        $description = isset($input['description']) ? trim((string) $input['description']) : null;
        $attributes = $input['attributes'] ?? $input['data'] ?? null;
        if ($attributes !== null && !is_array($attributes)) {
            return ['ok' => false, 'status' => 422, 'error' => 'attributes должен быть объектом'];
        }

        try {
            $product = $this->products->create(
                $scopeRow,
                $code,
                $name,
                $unit,
                $description,
                $attributes,
                (int) $session['user_id'],
                $barcodeEan13
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Конфликт уникальности', 'code' => 'duplicate'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка создания товара'];
        }

        $productId = (int) ($product['id'] ?? 0);
        $this->recordVersion($session, 'insert', null, $product);
        $this->audit->created($session, $productId, $code, ['name' => $name, 'unit' => $unit]);

        $shareResult = $this->applyDelegationShareFromInput($session, $productId, $input, $tenantId);
        if ($shareResult !== null && ($shareResult['ok'] ?? false) !== true) {
            return $shareResult;
        }
        if ($shareResult !== null) {
            $product = $this->products->findByIdInTenant($productId, $tenantId) ?? $product;
        }

        if (isset($input['external']) && is_array($input['external'])) {
            $extType = trim((string) ($input['external']['type'] ?? ''));
            $extId = trim((string) ($input['external']['id'] ?? ''));
            if ($extType !== '' && $extId !== '') {
                $this->bindExternalMeta($extType, $extId, $productId, $tenantId, (string) ($product['subtenant_id'] ?? $subtenantId), $product);
            }
        }

        return [
            'ok' => true,
            'status' => 201,
            'product' => $this->enrichProduct($product, $tenantId, $subtenantId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateProduct(array $session, int $id, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $before = $this->products->findVisibleById($session, $id);
        if ($before === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
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
        if (array_key_exists('unit', $input)) {
            $fields['unit'] = trim((string) $input['unit']);
        }
        if (array_key_exists('description', $input)) {
            $fields['description'] = trim((string) $input['description']);
        }
        if (array_key_exists('barcode_ean13', $input) || array_key_exists('ean13', $input) || array_key_exists('barcode', $input)) {
            $eanParsed = $this->parseEan13FromInput($input);
            if (($eanParsed['ok'] ?? false) !== true) {
                return ['ok' => false, 'status' => 422, 'error' => (string) ($eanParsed['error'] ?? 'EAN-13')];
            }
            $newEan = $eanParsed['ean13'] ?? null;
            if ($newEan !== null) {
                $other = $this->products->findByEan13InTenant($tenantId, $newEan);
                if ($other !== null && (int) ($other['id'] ?? 0) !== $id) {
                    return ['ok' => false, 'status' => 409, 'error' => 'EAN-13 уже у другого товара', 'code' => 'ean13_exists'];
                }
            }
            $fields['barcode_ean13'] = $newEan;
        }
        if (array_key_exists('attributes', $input) || array_key_exists('data', $input)) {
            $attrs = $input['attributes'] ?? $input['data'];
            if ($attrs !== null && !is_array($attrs)) {
                return ['ok' => false, 'status' => 422, 'error' => 'attributes должен быть объектом'];
            }
            $fields['attributes'] = $attrs;
        }

        if ($fields === []) {
            $after = $this->hasDelegationShareInput($input)
                ? ($this->products->findVisibleById($session, $id) ?? $before)
                : $before;

            return ['ok' => true, 'status' => 200, 'product' => $this->enrichProduct($after, $tenantId, $subtenantId)];
        }

        $this->products->update($id, $tenantId, $fields, (int) $session['user_id']);
        $after = $this->products->findVisibleById($session, $id) ?? $before;
        $this->recordVersion($session, 'update', $before, $after);
        $diff = ProductAudit::diff($before, $after, array_keys($fields));
        if ($diff !== []) {
            $this->audit->updated($session, $id, $diff, ['code' => (string) ($before['code'] ?? '')]);
        }

        return ['ok' => true, 'status' => 200, 'product' => $this->enrichProduct($after, $tenantId, $subtenantId)];
    }

    public function archiveProduct(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $before = $this->products->findVisibleById($session, $id);
        if ($before === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }

        if ((string) ($before['tenant_id'] ?? '') !== $tenantId) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Архивация сущности другого tenant только в его контексте',
                'code' => 'delegated_entity_read_only',
            ];
        }

        $this->products->update($id, $tenantId, ['status' => 'archived'], (int) $session['user_id']);
        $after = $this->products->findVisibleById($session, $id) ?? $before;
        $this->recordVersion($session, 'delete', $before, $after);
        $this->audit->archived($session, $id, (string) ($before['code'] ?? ''));

        return ['ok' => true, 'status' => 200, 'product' => $this->enrichProduct($after, $tenantId, $subtenantId)];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function bindExternal(array $session, int $id, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $product = $this->products->findVisibleById($session, $id);
        if ($product === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }

        if ((string) ($product['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 403, 'error' => 'Только в контексте владельца', 'code' => 'delegated_entity_read_only'];
        }

        $extType = trim((string) ($input['type'] ?? ''));
        $extId = trim((string) ($input['external_id'] ?? $input['meta'] ?? ''));
        if ($extType === '' || $extId === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'type и external_id обязательны'];
        }

        $this->bindExternalMeta($extType, $extId, $id, $tenantId, (string) ($product['subtenant_id'] ?? $subtenantId), $product);

        return ['ok' => true, 'status' => 200, 'product_id' => $id, 'external_type' => $extType, 'external_id' => $extId];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function enrichProducts(array $rows, string $tenantId, string $subtenantId): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i]['is_delegated_view'] = (string) ($row['tenant_id'] ?? '') !== $tenantId;
        }

        return $this->actors->enrichMany($rows, $tenantId, $subtenantId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function enrichProduct(array $row, string $tenantId, string $subtenantId): array
    {
        return $this->enrichProducts([$row], $tenantId, $subtenantId)[0] ?? $row;
    }

    private function generateCode(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) ?? '';
        $slug = trim($slug, '-');
        if ($slug === '') {
            $slug = 'sku';
        }
        if (strlen($slug) > 40) {
            $slug = substr($slug, 0, 40);
        }

        return 'sku-' . $slug . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, error?: string, code?: string}|null
     */
    private function applyDelegationShareFromInput(array $session, int $productId, array $input, string $ownerTenantId): ?array
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

        $this->products->update($productId, $ownerTenantId, [
            'shared_grant_tenant_ids_json' => $this->delegationShare->encodeJson($resolved['tenant_ids'] ?? null),
        ], (int) $session['user_id']);

        return ['ok' => true, 'status' => 200];
    }

    /**
     * @param array<string, mixed> $input
     */
    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, ean13?: ?string, error?: string}
     */
    private function parseEan13FromInput(array $input): array
    {
        $raw = $input['barcode_ean13'] ?? $input['ean13'] ?? $input['barcode'] ?? null;
        if (is_array($raw)) {
            $type = strtolower((string) ($raw['type'] ?? 'ean13'));
            if ($type !== 'ean13') {
                return ['ok' => false, 'error' => 'Поддерживается только barcode.type=ean13'];
            }
            $raw = $raw['value'] ?? $raw['code'] ?? '';
        }

        if ($raw === null || trim((string) $raw) === '') {
            return ['ok' => true, 'ean13' => null];
        }

        return Ean13::normalize((string) $raw);
    }

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
     * @param array<string, mixed> $product
     */
    private function bindExternalMeta(
        string $extType,
        string $extId,
        int $productId,
        string $tenantId,
        string $subtenantId,
        array $product,
    ): void {
        $oIndex = null;
        $oRef = (string) $productId;
        if (isset($product['project_id']) && $product['project_id'] !== null) {
            $oIndex = EntityMetaTypes::I_PROJECT;
            $oRef = (string) $product['project_id'];
        }

        $this->entityMeta->bind(
            $extType,
            $extId,
            EntityMetaTypes::I_PRODUCT,
            $productId,
            $tenantId,
            $subtenantId,
            $oIndex,
            $oRef
        );
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
            'maniforge_products',
            (string) ($row['id'] ?? ''),
            $op,
            $before,
            $after,
            (string) ($row['code'] ?? '')
        );
    }
}
