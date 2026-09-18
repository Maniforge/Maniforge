<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Database\Connection;
use App\Maniforge\Inventory\Security\InventoryPostingService;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Products\Support\Ean13;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Wms\Repository\MarkingCodeRepository;
use App\Maniforge\Wms\Repository\PackContentRepository;
use App\Maniforge\Wms\Repository\PackUnitRepository;
use App\Maniforge\Wms\Support\KizParser;
use App\Maniforge\Wms\Support\MarkingCodeTypes;
use App\Maniforge\Wms\Support\PackUnitTypes;
use App\Maniforge\Wms\Support\QrSsccGenerator;

final class WmsService
{
    public function __construct(
        private readonly PackUnitRepository $packs = new PackUnitRepository(),
        private readonly MarkingCodeRepository $markings = new MarkingCodeRepository(),
        private readonly PackContentRepository $contents = new PackContentRepository(),
        private readonly PackTreeExploder $exploder = new PackTreeExploder(),
        private readonly WmsScanService $scan = new WmsScanService(),
        private readonly InventoryPostingService $inventoryPosting = new InventoryPostingService(),
        private readonly WmsMarkingLifecycle $markingLifecycle = new WmsMarkingLifecycle(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly EntityScopeResolver $scopeResolver = new EntityScopeResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, pack?: array, error?: string, code?: string}
     */
    public function createPack(array $session, array $input): array
    {
        $unitType = strtolower(trim((string) ($input['unit_type'] ?? $input['unitType'] ?? '')));
        if (!in_array($unitType, PackUnitTypes::all(), true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'unit_type: consumer|group|pallet|sscc'];
        }

        $code = trim((string) ($input['code'] ?? ''));
        if ($code === '') {
            $code = $unitType . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        $resolved = $this->scopeResolver->resolveStockWriteScope($session, $input);
        if (($resolved['ok'] ?? false) !== true) {
            return [
                'ok' => false,
                'status' => (int) ($resolved['status'] ?? 422),
                'error' => (string) ($resolved['error'] ?? 'scope'),
            ];
        }

        $sscc = null;
        if (in_array($unitType, [PackUnitTypes::PALLET, PackUnitTypes::SSCC], true)) {
            $sscc = trim((string) ($input['sscc'] ?? ''));
            if ($sscc === '') {
                $sscc = QrSsccGenerator::sscc();
            }
        }

        $stockId = isset($input['stock_id']) ? (int) $input['stock_id'] : null;
        $productId = isset($input['product_id']) ? (int) $input['product_id'] : null;

        $pack = $this->packs->create(
            $resolved,
            $unitType,
            $code,
            $stockId > 0 ? $stockId : null,
            $productId > 0 ? $productId : null,
            (int) $session['user_id'],
            $sscc
        );

        return ['ok' => true, 'status' => 201, 'pack' => $pack];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, marking?: array, error?: string}
     */
    public function registerMarking(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $productId = (int) ($input['product_id'] ?? 0);
        $codeFull = trim((string) ($input['code'] ?? $input['code_full'] ?? ''));
        if ($productId <= 0 || $codeFull === '') {
            return ['ok' => false, 'status' => 422, 'error' => 'product_id и code обязательны'];
        }

        $product = $this->products->findVisibleById($session, $productId);
        if ($product === null || (string) ($product['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден'];
        }

        if ($this->markings->findByCode($tenantId, $codeFull) !== null) {
            return ['ok' => false, 'status' => 409, 'error' => 'КИЗ уже зарегистрирован', 'code' => 'duplicate_marking'];
        }

        $parsed = KizParser::parse($codeFull);
        $codeType = strtolower(trim((string) ($input['code_type'] ?? MarkingCodeTypes::KIZ)));
        $gtin = $parsed['gtin'] ?? null;
        if ($gtin === null && $product !== null && !empty($product['barcode_ean13'])) {
            $gtin = '0' . (string) $product['barcode_ean13'];
        }

        $id = $this->markings->create($tenantId, [
            'product_id' => $productId,
            'code_full' => $codeFull,
            'code_type' => $codeType,
            'gtin' => $gtin,
            'serial' => $parsed['serial'] ?? null,
            'crypto_tail' => $parsed['crypto_tail'] ?? null,
        ]);

        return [
            'ok' => true,
            'status' => 201,
            'marking' => $this->markings->findByIdInTenant($id, $tenantId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addMarkingToPack(array $session, int $packId, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $pack = $this->packs->findVisibleById($session, $packId);
        if ($pack === null || (string) ($pack['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 404, 'error' => 'Упаковка не найдена'];
        }
        if ((string) ($pack['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'status' => 422, 'error' => 'Только draft упаковка', 'code' => 'pack_sealed'];
        }
        if (!in_array((string) ($pack['unit_type'] ?? ''), [PackUnitTypes::GROUP, PackUnitTypes::CONSUMER], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'КИЗ добавляются в group/consumer'];
        }

        $markingId = (int) ($input['marking_code_id'] ?? 0);
        $code = trim((string) ($input['code'] ?? ''));
        if ($markingId <= 0 && $code !== '') {
            $m = $this->markings->findByCode($tenantId, $code);
            if ($m === null) {
                return ['ok' => false, 'status' => 404, 'error' => 'КИЗ не найден — сначала register'];
            }
            $markingId = (int) $m['id'];
        }
        if ($markingId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'marking_code_id или code'];
        }

        $marking = $this->markings->findByIdInTenant($markingId, $tenantId);
        if ($marking === null || (string) ($marking['status'] ?? '') !== 'available') {
            return ['ok' => false, 'status' => 422, 'error' => 'КИЗ недоступен', 'code' => 'marking_unavailable'];
        }

        $lineNo = $this->contents->countByParent($packId) + 1;
        $this->contents->addMarking($packId, $markingId, $lineNo);
        $this->markings->updateStatus($markingId, $tenantId, 'in_group', $packId);

        return [
            'ok' => true,
            'status' => 200,
            'pack_id' => $packId,
            'marking_code_id' => $markingId,
            'contents' => $this->contents->listByParent($packId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function addChildPack(array $session, int $parentPackId, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        $parent = $this->packs->findVisibleById($session, $parentPackId);
        if ($parent === null || (string) ($parent['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 404, 'error' => 'Родитель не найден'];
        }
        if ((string) ($parent['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'status' => 422, 'error' => 'Родитель должен быть draft'];
        }

        $childId = (int) ($input['child_pack_unit_id'] ?? $input['child_pack_id'] ?? 0);
        $child = $this->packs->findVisibleById($session, $childId);
        if ($child === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Дочерняя упаковка не найдена'];
        }
        if (!PackUnitTypes::canContain((string) $parent['unit_type'], (string) $child['unit_type'])) {
            return ['ok' => false, 'status' => 422, 'error' => 'Недопустимая вложенность'];
        }
        if ((string) ($child['status'] ?? '') !== 'sealed') {
            return ['ok' => false, 'status' => 422, 'error' => 'Дочерняя упаковка должна быть sealed'];
        }

        $lineNo = $this->contents->countByParent($parentPackId) + 1;
        $this->contents->addChildPack($parentPackId, $childId, $lineNo);

        return [
            'ok' => true,
            'status' => 200,
            'contents' => $this->contents->listByParent($parentPackId),
        ];
    }

    public function sealPack(array $session, int $packId): array
    {
        $tenantId = (string) $session['tenant_id'];
        $pack = $this->packs->findVisibleById($session, $packId);
        if ($pack === null || (string) ($pack['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 404, 'error' => 'Упаковка не найдена'];
        }
        if ($this->contents->countByParent($packId) < 1) {
            return ['ok' => false, 'status' => 422, 'error' => 'Пустая упаковка'];
        }

        $this->packs->update($packId, $tenantId, [
            'status' => 'sealed',
            'sealed_at' => gmdate('Y-m-d H:i:s'),
            'sealed_by' => (int) $session['user_id'],
        ]);

        $fresh = $this->packs->findByIdInTenant($packId, $tenantId);
        if ($fresh !== null && in_array((string) ($fresh['unit_type'] ?? ''), [PackUnitTypes::PALLET, PackUnitTypes::SSCC], true)) {
            $qr = QrSsccGenerator::qrPayload($fresh);
            $this->packs->update($packId, $tenantId, [
                'qr_payload' => $qr,
                'qr_lookup' => QrSsccGenerator::qrLookup($qr),
            ]);
            $fresh = $this->packs->findByIdInTenant($packId, $tenantId);
        }

        return ['ok' => true, 'status' => 200, 'pack' => $fresh];
    }

    public function getPack(array $session, int $packId): array
    {
        $pack = $this->packs->findVisibleById($session, $packId);
        if ($pack === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Не найдено'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'pack' => $pack,
            'contents' => $this->contents->listByParent($packId),
            'markings' => $this->markings->listByPackUnit($packId),
        ];
    }

    public function deleteDraftPack(array $session, int $packId): array
    {
        $tenantId = (string) $session['tenant_id'];
        $pack = $this->packs->findVisibleById($session, $packId);
        if ($pack === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Не найдено'];
        }
        if ((string) ($pack['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 403, 'error' => 'Удаление только в tenant владельца'];
        }
        if ((string) ($pack['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'status' => 422, 'error' => 'Только draft упаковку можно удалить', 'code' => 'not_draft'];
        }

        if (!$this->packs->deleteDraft($packId, $tenantId)) {
            return ['ok' => false, 'status' => 404, 'error' => 'Не удалось удалить'];
        }

        return ['ok' => true, 'status' => 200, 'deleted' => true, 'id' => $packId];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listPacks(array $session, array $query): array
    {
        $filters = ['limit' => isset($query['limit']) ? (int) $query['limit'] : 50];
        foreach (['unit_type', 'status', 'search'] as $key) {
            if (!empty($query[$key])) {
                $filters[$key] = trim((string) $query[$key]);
            }
        }

        $items = $this->packs->listVisible($session, $filters);
        foreach ($items as $i => $row) {
            $items[$i]['is_delegated_view'] =
                (string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id'];
        }

        return ['ok' => true, 'status' => 200, 'items' => $items];
    }

    /**
     * @param array<string, mixed> $query
     */
    public function listMarkings(array $session, array $query): array
    {
        $filters = ['limit' => isset($query['limit']) ? (int) $query['limit'] : 50];
        if (!empty($query['product_id'])) {
            $filters['product_id'] = (int) $query['product_id'];
        }
        foreach (['status', 'search'] as $key) {
            if (!empty($query[$key])) {
                $filters[$key] = trim((string) $query[$key]);
            }
        }

        return ['ok' => true, 'status' => 200, 'items' => $this->markings->listVisible($session, $filters)];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function bulkRegisterMarkings(array $session, array $input): array
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $codes = $input['codes'] ?? $input['items'] ?? null;
        if ($productId <= 0 || !is_array($codes) || $codes === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'product_id и codes[] обязательны'];
        }

        $created = [];
        $errors = [];
        foreach ($codes as $idx => $raw) {
            $code = is_string($raw) ? $raw : (is_array($raw) ? (string) ($raw['code'] ?? $raw['code_full'] ?? '') : '');
            if ($code === '') {
                $errors[] = ['index' => $idx, 'error' => 'Пустой код'];
                continue;
            }
            $one = $this->registerMarking($session, [
                'product_id' => $productId,
                'code' => $code,
                'code_type' => is_array($raw) ? ($raw['code_type'] ?? 'kiz') : 'kiz',
            ]);
            if (($one['ok'] ?? false) === true) {
                $created[] = $one['marking'] ?? [];
            } else {
                $errors[] = ['index' => $idx, 'code' => $code, 'error' => $one['error'] ?? ''];
            }
        }

        return [
            'ok' => true,
            'status' => 201,
            'created_count' => count($created),
            'created' => $created,
            'errors' => $errors,
        ];
    }

    public function disaggregatePack(array $session, int $packId): array
    {
        $tenantId = (string) $session['tenant_id'];
        $pack = $this->packs->findVisibleById($session, $packId);
        if ($pack === null || (string) ($pack['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 404, 'error' => 'Упаковка не найдена'];
        }

        $status = (string) ($pack['status'] ?? '');
        if (!in_array($status, ['sealed', 'at_stock'], true)) {
            return [
                'ok' => false,
                'status' => 422,
                'error' => 'Дизагрегация только для sealed или at_stock',
                'code' => 'invalid_pack_status',
            ];
        }

        $pdo = Connection::get();
        try {
            $pdo->beginTransaction();
            foreach ($this->contents->listByParent($packId) as $row) {
                if (!empty($row['marking_code_id'])) {
                    $this->markings->updateStatus((int) $row['marking_code_id'], $tenantId, 'available', null, null);
                }
            }
            $this->contents->deleteByParent($packId);
            $this->packs->update($packId, $tenantId, ['status' => 'disaggregated']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка дизагрегации'];
        }

        return [
            'ok' => true,
            'status' => 200,
            'pack' => $this->packs->findByIdInTenant($packId, $tenantId),
        ];
    }

    public function getMarking(array $session, int $id): array
    {
        $tenantId = (string) $session['tenant_id'];
        $row = $this->markings->findByIdInTenant($id, $tenantId);
        if ($row === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'КИЗ не найден'];
        }
        if ($this->products->findVisibleById($session, (int) $row['product_id']) === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'КИЗ не найден'];
        }

        return ['ok' => true, 'status' => 200, 'marking' => $row];
    }

    public function traceMarking(array $session, int $id): array
    {
        $got = $this->getMarking($session, $id);
        if (($got['ok'] ?? false) !== true) {
            return $got;
        }

        return [
            'ok' => true,
            'status' => 200,
            'marking' => $got['marking'],
            'movements' => $this->markings->traceMovements((string) $session['tenant_id'], $id),
        ];
    }

    public function scan(array $session, string $code): array
    {
        return $this->scan->resolve($session, $code);
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function syncAfterMovementReversal(string $tenantId, string $originalMovementType, array $lines, ?int $packUnitId): void
    {
        $this->markingLifecycle->syncAfterMovementReversal($tenantId, $originalMovementType, $lines, $packUnitId);
    }

    /**
     * Проведение движения по скану паллеты/ГУ/КИЗ.
     *
     * @param array<string, mixed> $input
     */
    public function postMovementByScan(array $session, array $input): array
    {
        $type = strtolower(trim((string) ($input['movement_type'] ?? MovementTypes::RECEIPT)));
        if (!in_array($type, [MovementTypes::RECEIPT, MovementTypes::ISSUE, MovementTypes::TRANSFER], true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'movement_type: receipt|issue|transfer'];
        }

        $fromStockId = (int) ($input['from_stock_id'] ?? $input['fromStockId'] ?? 0);
        $toStockId = (int) ($input['to_stock_id'] ?? $input['toStockId'] ?? 0);
        $stockId = (int) ($input['stock_id'] ?? $input['stockId'] ?? 0);
        if ($type === MovementTypes::TRANSFER) {
            if ($fromStockId <= 0 || $toStockId <= 0) {
                return ['ok' => false, 'status' => 422, 'error' => 'transfer: from_stock_id и to_stock_id'];
            }
            $stockId = $fromStockId;
        }
        $scanCode = trim((string) ($input['scan'] ?? $input['code'] ?? ''));
        $packUnitId = (int) ($input['pack_unit_id'] ?? 0);

        $tenantId = (string) $session['tenant_id'];
        $lines = [];

        if ($scanCode !== '') {
            $resolved = $this->scan->resolve($session, $scanCode);
            if (($resolved['ok'] ?? false) !== true) {
                return $resolved;
            }
            if (($resolved['kind'] ?? '') === 'product') {
                $product = $resolved['product'] ?? [];
                $qty = trim((string) ($input['qty'] ?? $input['quantity'] ?? '1'));
                if (!is_numeric($qty) || bccomp($qty, '0', 6) <= 0) {
                    return ['ok' => false, 'status' => 422, 'error' => 'qty > 0 для штрихкода товара'];
                }
                if ($type === MovementTypes::TRANSFER) {
                    return ['ok' => false, 'status' => 422, 'error' => 'transfer по EAN-13: используйте КИЗ или pack'];
                }
                $targetStock = $type === MovementTypes::TRANSFER ? 0 : ($stockId > 0 ? $stockId : 0);
                if ($targetStock <= 0) {
                    return ['ok' => false, 'status' => 422, 'error' => 'stock_id обязателен'];
                }
                $qtyDelta = $type === MovementTypes::ISSUE ? '-' . $qty : $qty;
                $lines[] = [
                    'product_id' => (int) ($product['id'] ?? 0),
                    'stock_id' => $targetStock,
                    'qty_delta' => $qtyDelta,
                ];
            } elseif (($resolved['kind'] ?? '') === 'marking') {
                $marking = $resolved['marking'] ?? [];
                $qty = $type === MovementTypes::ISSUE ? '-1' : '1';
                $lines[] = [
                    'product_id' => (int) ($marking['product_id'] ?? 0),
                    'stock_id' => $stockId,
                    'qty_delta' => $qty,
                    'marking_code_id' => (int) ($marking['id'] ?? 0),
                ];
            } else {
                $packUnitId = (int) (($resolved['pack']['id'] ?? 0));
            }
        }

        if ($packUnitId > 0 && $lines === []) {
            $exploded = $this->exploder->explodeToMovementLines($tenantId, $packUnitId, $stockId, $type);
            if (($exploded['ok'] ?? false) !== true) {
                return $exploded;
            }
            $lines = $exploded['lines'] ?? [];
        }

        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'scan или pack_unit_id'];
        }

        if ($type === MovementTypes::TRANSFER) {
            $transferLines = [];
            foreach ($lines as $line) {
                $qty = (string) ($line['qty_delta'] ?? '1');
                if (bccomp($qty, '0', 6) < 0) {
                    $qty = ltrim($qty, '-');
                }
                $base = [
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'marking_code_id' => $line['marking_code_id'] ?? null,
                    'pack_unit_id' => $line['pack_unit_id'] ?? null,
                    'batch_code' => $line['batch_code'] ?? null,
                    'lot_code' => $line['lot_code'] ?? null,
                ];
                $transferLines[] = array_merge($base, [
                    'stock_id' => $fromStockId,
                    'qty_delta' => '-' . $qty,
                ]);
                $transferLines[] = array_merge($base, [
                    'stock_id' => $toStockId,
                    'qty_delta' => $qty,
                ]);
            }
            $lines = $transferLines;
            $type = MovementTypes::TRANSFER;
        } else {
            if ($stockId <= 0) {
                $stockId = (int) ($lines[0]['stock_id'] ?? 0);
            }
            foreach ($lines as &$line) {
                if ((int) ($line['stock_id'] ?? 0) <= 0) {
                    $line['stock_id'] = $stockId;
                }
                if (!empty($input['batch_code']) && empty($line['batch_code'])) {
                    $line['batch_code'] = (string) $input['batch_code'];
                }
                if (!empty($input['lot_code']) && empty($line['lot_code'])) {
                    $line['lot_code'] = (string) $input['lot_code'];
                }
            }
            unset($line);
        }

        $postInput = array_merge($input, [
            'movement_type' => $type,
            'lines' => $lines,
            'metadata' => array_merge(
                is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                ['wms_scan' => $scanCode !== '' ? $scanCode : null, 'pack_unit_id' => $packUnitId > 0 ? $packUnitId : null]
            ),
        ]);
        unset($postInput['pack_unit_id'], $postInput['packUnitId'], $postInput['scan'], $postInput['code']);

        $result = $this->inventoryPosting->postMovement($session, $postInput);
        if (($result['ok'] ?? false) === true) {
            $this->markingLifecycle->afterMovementPosted($tenantId, $type, $lines, $packUnitId);
        }

        return $result;
    }
}
