<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Security;

use App\Database\Connection;
use App\Maniforge\Inventory\Repository\BalanceRepository;
use App\Maniforge\Inventory\Repository\MovementRepository;
use App\Maniforge\Inventory\Support\InventoryStockTypes;
use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Products\Repository\ProductRepository;
use App\Maniforge\Rbac\Security\EntityDelegationShareService;
use App\Maniforge\Rbac\Security\RbacService;
use App\Maniforge\Rbac\Support\EntityScopeResolver;
use App\Maniforge\Versioning\Security\ChangeRecorder;
use App\Maniforge\Versioning\Support\VersioningScope;
use App\Maniforge\Inventory\Support\InventoryAudit;
use App\Maniforge\Warehouses\Repository\StockRepository;
use App\Maniforge\Wms\Security\WmsMarkingLifecycle;

final class InventoryPostingService
{
    public function __construct(
        private readonly BalanceRepository $balances = new BalanceRepository(),
        private readonly MovementRepository $movements = new MovementRepository(),
        private readonly ProductRepository $products = new ProductRepository(),
        private readonly StockRepository $stocks = new StockRepository(),
        private readonly EntityScopeResolver $scopeResolver = new EntityScopeResolver(),
        private readonly EntityDelegationShareService $delegationShare = new EntityDelegationShareService(),
        private readonly RbacService $rbac = new RbacService(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
        private readonly InventoryAudit $audit = new InventoryAudit(),
        private readonly WmsMarkingLifecycle $wmsMarking = new WmsMarkingLifecycle(),
    ) {
    }

    /**
     * Сторно проведённого движения (обратные строки, без внешних интеграций).
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, movement?: array, error?: string, code?: string}
     */
    public function reverseMovement(array $session, int $movementId, array $input = []): array
    {
        $orig = $this->movements->findVisibleById($session, $movementId);
        if ($orig === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Движение не найдено'];
        }
        if ((string) ($orig['status'] ?? '') !== 'posted') {
            return ['ok' => false, 'status' => 422, 'error' => 'Сторно только для posted'];
        }
        if ((string) ($orig['tenant_id'] ?? '') !== (string) $session['tenant_id']) {
            return ['ok' => false, 'status' => 403, 'error' => 'Сторно только в tenant владельца', 'code' => 'delegated_entity_read_only'];
        }
        if ($this->movements->hasReversal($movementId)) {
            return ['ok' => false, 'status' => 409, 'error' => 'Движение уже сторнировано', 'code' => 'already_reversed'];
        }

        $origMeta = is_array($orig['metadata'] ?? null) ? $orig['metadata'] : [];
        if (isset($origMeta['reversal_of'])) {
            return ['ok' => false, 'status' => 422, 'error' => 'Нельзя сторнировать сторно', 'code' => 'is_reversal'];
        }

        $lines = [];
        foreach ($orig['lines'] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }
            $delta = (string) ($line['qty_delta'] ?? '0');
            if (bccomp($delta, '0', 6) === 0) {
                continue;
            }
            $neg = $this->negateQty($delta);
            $row = [
                'product_id' => (int) ($line['product_id'] ?? 0),
                'stock_id' => (int) ($line['stock_id'] ?? 0),
                'qty_delta' => $neg,
            ];
            foreach (['pack_unit_id', 'marking_code_id', 'batch_code', 'lot_code'] as $key) {
                if (!empty($line[$key])) {
                    $row[$key] = $line[$key];
                }
            }
            $lines[] = $row;
        }

        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'Нет строк для сторно'];
        }

        $docNumber = trim((string) ($input['doc_number'] ?? ''));
        if ($docNumber === '') {
            $docNumber = (string) ($orig['doc_number'] ?? 'mov') . '-rev-' . substr(bin2hex(random_bytes(2)), 0, 4);
        }

        $postInput = [
            'movement_type' => (string) ($orig['movement_type'] ?? MovementTypes::ADJUSTMENT),
            'lines' => $lines,
            'doc_number' => strtolower($docNumber),
            'note' => trim((string) ($input['note'] ?? 'Сторно #' . $movementId)),
            'metadata' => array_merge(
                is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
                ['reversal_of' => $movementId]
            ),
            'scope_visibility' => $orig['scope_visibility'] ?? 'project',
            'project_id' => $orig['project_id'] ?? null,
            'subtenant_id' => $orig['subtenant_id'] ?? '',
        ];

        $result = $this->postMovement($session, $postInput);
        if (($result['ok'] ?? false) === true) {
            $revId = (int) (($result['movement']['id'] ?? 0));
            if ($revId > 0) {
                $this->movements->markReversed($movementId, $revId);
            }
            $packId = isset($origMeta['pack_unit_id']) ? (int) $origMeta['pack_unit_id'] : 0;
            if ($packId <= 0) {
                foreach ($lines as $line) {
                    if (!empty($line['pack_unit_id'])) {
                        $packId = (int) $line['pack_unit_id'];
                        break;
                    }
                }
            }
            $hasMarking = false;
            foreach ($lines as $line) {
                if (!empty($line['marking_code_id'])) {
                    $hasMarking = true;
                    break;
                }
            }
            if ($hasMarking || $packId > 0) {
                $this->wmsMarking->syncAfterMovementReversal(
                    (string) $session['tenant_id'],
                    (string) ($orig['movement_type'] ?? ''),
                    $lines,
                    $packId > 0 ? $packId : null
                );
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, movement?: array, error?: string, code?: string}
     */
    public function postMovement(array $session, array $input): array
    {
        $tenantId = (string) $session['tenant_id'];
        if ((string) ($session['tenant_id'] ?? '') === '') {
            return ['ok' => false, 'status' => 403, 'error' => 'Движение только в контексте владельца tenant'];
        }

        $type = strtolower(trim((string) ($input['movement_type'] ?? $input['type'] ?? '')));
        if (!in_array($type, MovementTypes::all(), true)) {
            return ['ok' => false, 'status' => 422, 'error' => 'movement_type: receipt|issue|transfer|adjustment'];
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

        if ($this->hasDelegationShareInput($input)) {
            if (!$this->canConfigureDelegationShare($session)) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'error' => 'delegation_share требует tenant_admin',
                    'code' => 'delegation_share_forbidden',
                ];
            }
            $share = $this->delegationShare->resolveForOwner($tenantId, $input);
            if (($share['ok'] ?? false) !== true) {
                return ['ok' => false, 'status' => (int) ($share['status'] ?? 422), 'error' => (string) ($share['error'] ?? '')];
            }
            $scopeRow['shared_grant_tenant_ids'] = $share['tenant_ids'] ?? null;
        }

        $linesResult = $this->buildLines($session, $tenantId, $type, $input);
        if (($linesResult['ok'] ?? false) !== true) {
            return $linesResult;
        }
        /** @var list<array{product_id: int, stock_id: int, qty_delta: string}> $lines */
        $lines = $linesResult['lines'];

        $docNumber = trim((string) ($input['doc_number'] ?? $input['docNumber'] ?? ''));
        if ($docNumber === '') {
            $docNumber = 'mov-' . gmdate('Ymd') . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
        }
        $docNumber = strtolower($docNumber);

        $note = isset($input['note']) ? trim((string) $input['note']) : null;
        $metadata = $input['metadata'] ?? null;
        if ($metadata !== null && !is_array($metadata)) {
            return ['ok' => false, 'status' => 422, 'error' => 'metadata должен быть объектом'];
        }

        if ($this->isDraftRequest($input)) {
            return $this->saveDraftMovement($session, $scopeRow, $docNumber, $type, $note, $metadata, $lines);
        }

        $pdo = Connection::get();
        try {
            $pdo->beginTransaction();

            foreach ($lines as $line) {
                $this->assertSufficientQty($tenantId, $line);
                $this->balances->applyDelta(
                    $tenantId,
                    (int) $line['product_id'],
                    (int) $line['stock_id'],
                    (string) $line['qty_delta']
                );
            }

            $movementId = $this->movements->insertPosted(
                $scopeRow,
                $docNumber,
                $type,
                $note,
                $metadata,
                (int) $session['user_id'],
                $lines
            );

            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() === 'insufficient_qty') {
                return [
                    'ok' => false,
                    'status' => 409,
                    'error' => 'Недостаточно остатка',
                    'code' => 'insufficient_qty',
                ];
            }

            throw $e;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && $e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Конфликт doc_number или данных', 'code' => 'duplicate'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка проведения движения'];
        }

        $movement = $this->movements->findVisibleById($session, $movementId);
        if ($movement !== null) {
            $this->versioning->record(
                VersioningScope::fromSession($session, $movement['project_id'] ?? null),
                'maniforge_inv_movements',
                (string) $movementId,
                'insert',
                null,
                $movement,
                (string) ($movement['doc_number'] ?? '')
            );
            $this->audit->movementPosted($session, $movementId, $docNumber, $type, [
                'lines_count' => count($lines),
            ]);
        }

        return [
            'ok' => true,
            'status' => 201,
            'movement' => $movement,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, lines?: list<array{product_id: int, stock_id: int, qty_delta: string}>, error?: string, code?: string}
     */
    private function buildLines(array $session, string $tenantId, string $type, array $input): array
    {
        if ($type === MovementTypes::TRANSFER) {
            $rawLines = $input['lines'] ?? null;
            if (is_array($rawLines) && $rawLines !== []) {
                return $this->buildTransferFromLines($session, $tenantId, $rawLines);
            }

            return $this->buildTransferLines($session, $tenantId, $input);
        }

        if ($type === MovementTypes::ADJUSTMENT) {
            return $this->buildAdjustmentLines($session, $tenantId, $input);
        }

        $packUnitId = (int) ($input['pack_unit_id'] ?? $input['packUnitId'] ?? 0);
        if ($packUnitId > 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'pack_unit_id: используйте WMS POST /api/v1/movements/scan'];
        }

        $rawLines = $input['lines'] ?? null;
        if (!is_array($rawLines) || $rawLines === []) {
            return $this->buildSingleLineFromFlat($session, $tenantId, $type, $input);
        }

        $lines = [];
        foreach ($rawLines as $row) {
            if (!is_array($row)) {
                continue;
            }
            $built = $this->buildOneLine($session, $tenantId, $row, $type);
            if (($built['ok'] ?? false) !== true) {
                return $built;
            }
            $lines[] = $built['line'];
        }

        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'lines обязателен'];
        }

        return ['ok' => true, 'status' => 200, 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, lines?: list<array>, error?: string, code?: string}
     */
    /**
     * @param list<array<string, mixed>> $rawLines
     * @return array{ok: bool, status: int, lines?: list<array>, error?: string}
     */
    private function buildTransferFromLines(array $session, string $tenantId, array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $row) {
            if (!is_array($row)) {
                continue;
            }
            $productId = (int) ($row['product_id'] ?? 0);
            $stockId = (int) ($row['stock_id'] ?? 0);
            $delta = isset($row['qty_delta']) ? (string) $row['qty_delta'] : null;
            if ($delta === null || bccomp($delta, '0', 6) === 0) {
                return ['ok' => false, 'status' => 422, 'error' => 'transfer lines: qty_delta обязателен'];
            }
            $built = $this->buildOneLineWrapped($session, $tenantId, $productId, $stockId, $delta, MovementTypes::TRANSFER, $row);
            if (($built['ok'] ?? false) !== true) {
                return $built;
            }
            $lines[] = $built['line'];
        }

        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'transfer lines пуст'];
        }

        return ['ok' => true, 'status' => 200, 'lines' => $lines];
    }

    private function buildTransferLines(array $session, string $tenantId, array $input): array
    {
        $productId = (int) ($input['product_id'] ?? $input['productId'] ?? 0);
        $fromStockId = (int) ($input['from_stock_id'] ?? $input['fromStockId'] ?? 0);
        $toStockId = (int) ($input['to_stock_id'] ?? $input['toStockId'] ?? 0);
        $qty = $this->normalizeQty($input['qty'] ?? $input['quantity'] ?? null);

        if ($productId <= 0 || $fromStockId <= 0 || $toStockId <= 0 || $qty === null || bccomp($qty, '0', 6) <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'transfer: product_id, from_stock_id, to_stock_id, qty > 0'];
        }
        if ($fromStockId === $toStockId) {
            return ['ok' => false, 'status' => 422, 'error' => 'from_stock_id и to_stock_id должны различаться'];
        }

        $product = $this->requireProduct($session, $productId);
        if (($product['ok'] ?? false) !== true) {
            return $product;
        }
        $from = $this->requireStock($session, $fromStockId);
        if (($from['ok'] ?? false) !== true) {
            return $from;
        }
        $to = $this->requireStock($session, $toStockId);
        if (($to['ok'] ?? false) !== true) {
            return $to;
        }

        if ((string) ($from['row']['tenant_id'] ?? '') !== $tenantId || (string) ($to['row']['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 422, 'error' => 'Склады должны быть в том же tenant'];
        }

        $neg = $this->negateQty($qty);

        return [
            'ok' => true,
            'status' => 200,
            'lines' => [
                ['product_id' => $productId, 'stock_id' => $fromStockId, 'qty_delta' => $neg],
                ['product_id' => $productId, 'stock_id' => $toStockId, 'qty_delta' => $qty],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, lines?: list<array>, error?: string, code?: string}
     */
    private function buildAdjustmentLines(array $session, string $tenantId, array $input): array
    {
        $productId = (int) ($input['product_id'] ?? 0);
        $stockId = (int) ($input['stock_id'] ?? 0);
        if ($productId <= 0 || $stockId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'adjustment: product_id и stock_id обязательны'];
        }

        $product = $this->requireProduct($session, $productId);
        if (($product['ok'] ?? false) !== true) {
            return $product;
        }
        $stock = $this->requireStock($session, $stockId);
        if (($stock['ok'] ?? false) !== true) {
            return $stock;
        }

        $current = $this->balances->qtyForPair($tenantId, $productId, $stockId);

        if (array_key_exists('qty_after', $input) || array_key_exists('qtyAfter', $input)) {
            $target = $this->normalizeQty($input['qty_after'] ?? $input['qtyAfter']);
            if ($target === null) {
                return ['ok' => false, 'status' => 422, 'error' => 'qty_after обязателен'];
            }
            $delta = bcsub($target, $current, 6);
        } else {
            $delta = $this->normalizeQty($input['qty_delta'] ?? $input['qty'] ?? null);
            if ($delta === null || bccomp($delta, '0', 6) === 0) {
                return ['ok' => false, 'status' => 422, 'error' => 'qty_delta или qty_after обязателен'];
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'lines' => [
                ['product_id' => $productId, 'stock_id' => $stockId, 'qty_delta' => $delta],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, lines?: list<array>, error?: string, code?: string}
     */
    private function buildSingleLineFromFlat(array $session, string $tenantId, string $type, array $input): array
    {
        $productId = (int) ($input['product_id'] ?? $input['productId'] ?? 0);
        $stockId = (int) ($input['stock_id'] ?? $input['stockId'] ?? 0);
        $qty = $this->normalizeQty($input['qty'] ?? $input['quantity'] ?? null);

        if ($productId <= 0 || $stockId <= 0 || $qty === null || bccomp($qty, '0', 6) <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'product_id, stock_id, qty > 0 обязательны'];
        }

        $delta = $type === MovementTypes::ISSUE ? $this->negateQty($qty) : $qty;

        $extras = [];
        foreach (['pack_unit_id', 'marking_code_id', 'batch_code', 'lot_code'] as $key) {
            if (!empty($input[$key])) {
                $extras[$key] = $input[$key];
            }
        }

        $one = $this->buildOneLineWrapped($session, $tenantId, $productId, $stockId, $delta, $type, $extras);
        if (($one['ok'] ?? false) !== true) {
            return $one;
        }

        return ['ok' => true, 'status' => 200, 'lines' => [$one['line']]];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{ok: bool, status: int, line?: array, error?: string, code?: string}
     */
    private function buildOneLine(array $session, string $tenantId, array $row, string $type): array
    {
        $productId = (int) ($row['product_id'] ?? 0);
        $stockId = (int) ($row['stock_id'] ?? 0);

        if (isset($row['qty_delta']) && is_numeric((string) $row['qty_delta'])) {
            $qtyDelta = (string) $row['qty_delta'];
            if (bccomp($qtyDelta, '0', 6) === 0) {
                return ['ok' => false, 'status' => 422, 'error' => 'qty_delta в строке не может быть 0'];
            }

            return $this->buildOneLineWrapped($session, $tenantId, $productId, $stockId, $qtyDelta, $type, $row);
        }

        $qty = $this->normalizeQty($row['qty'] ?? $row['quantity'] ?? null);
        if ($qty === null || bccomp($qty, '0', 6) === 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'qty в строке обязателен'];
        }

        if ($type === MovementTypes::RECEIPT && bccomp($qty, '0', 6) < 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'receipt: qty должен быть положительным'];
        }
        if ($type === MovementTypes::ISSUE && bccomp($qty, '0', 6) > 0) {
            $qty = $this->negateQty($qty);
        }

        return $this->buildOneLineWrapped($session, $tenantId, $productId, $stockId, $qty, $type, $row);
    }

    /**
     * @param array<string, mixed> $extras
     * @return array{ok: bool, status: int, line?: array, error?: string, code?: string}
     */
    private function buildOneLineWrapped(
        array $session,
        string $tenantId,
        int $productId,
        int $stockId,
        string $qtyDelta,
        string $type,
        array $extras = [],
    ): array {
        $product = $this->requireProduct($session, $productId);
        if (($product['ok'] ?? false) !== true) {
            return $product;
        }
        $stock = $this->requireStock($session, $stockId);
        if (($stock['ok'] ?? false) !== true) {
            return $stock;
        }
        if ((string) ($stock['row']['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 422, 'error' => 'product и stock в разных tenant'];
        }

        $line = [
            'product_id' => $productId,
            'stock_id' => $stockId,
            'qty_delta' => $qtyDelta,
        ];
        foreach (['pack_unit_id', 'marking_code_id', 'batch_code', 'lot_code', 'lot_id'] as $key) {
            if (array_key_exists($key, $extras) && $extras[$key] !== null && $extras[$key] !== '') {
                $line[$key] = $extras[$key];
            }
        }

        return [
            'ok' => true,
            'status' => 200,
            'line' => $line,
        ];
    }

    /**
     * @param array<string, mixed> $scopeRow
     * @param list<array<string, mixed>> $lines
     * @return array{ok: bool, status: int, movement?: array, error?: string, code?: string}
     */
    public function saveDraftMovement(
        array $session,
        array $scopeRow,
        string $docNumber,
        string $movementType,
        ?string $note,
        ?array $metadata,
        array $lines,
    ): array {
        try {
            $movementId = $this->movements->insertDraft(
                $scopeRow,
                $docNumber,
                $movementType,
                $note,
                $metadata,
                (int) $session['user_id'],
                $lines
            );
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'status' => 409, 'error' => 'Конфликт doc_number', 'code' => 'duplicate'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка сохранения черновика'];
        }

        $movement = $this->movements->findVisibleById($session, $movementId);

        return ['ok' => true, 'status' => 201, 'movement' => $movement];
    }

    public function postDraftMovement(array $session, int $movementId): array
    {
        $tenantId = (string) $session['tenant_id'];
        $movement = $this->movements->findVisibleById($session, $movementId);
        if ($movement === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Движение не найдено'];
        }
        if ((string) ($movement['status'] ?? '') !== 'draft') {
            return ['ok' => false, 'status' => 422, 'error' => 'Только draft можно провести', 'code' => 'not_draft'];
        }
        if ((string) ($movement['tenant_id'] ?? '') !== $tenantId) {
            return ['ok' => false, 'status' => 403, 'error' => 'Проведение только в tenant владельца'];
        }

        $pdo = Connection::get();
        try {
            $pdo->beginTransaction();
            foreach ($movement['lines'] ?? [] as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $row = [
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'stock_id' => (int) ($line['stock_id'] ?? 0),
                    'qty_delta' => (string) ($line['qty_delta'] ?? '0'),
                ];
                $this->assertSufficientQty($tenantId, $row);
                $this->balances->applyDelta($tenantId, $row['product_id'], $row['stock_id'], $row['qty_delta']);
            }
            if (!$this->movements->markPosted($movementId, $tenantId, (int) $session['user_id'])) {
                throw new \RuntimeException('draft_post_failed');
            }
            $pdo->commit();
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getMessage() === 'insufficient_qty') {
                return ['ok' => false, 'status' => 409, 'error' => 'Недостаточно остатка', 'code' => 'insufficient_qty'];
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка проведения черновика'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка проведения черновика'];
        }

        $posted = $this->movements->findVisibleById($session, $movementId);

        return ['ok' => true, 'status' => 200, 'movement' => $posted];
    }

    public function cancelDraftMovement(array $session, int $movementId): array
    {
        $tenantId = (string) $session['tenant_id'];
        if (!$this->movements->deleteDraft($movementId, $tenantId)) {
            return ['ok' => false, 'status' => 404, 'error' => 'Черновик не найден'];
        }

        return ['ok' => true, 'status' => 200, 'cancelled' => true];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function isDraftRequest(array $input): bool
    {
        if (strtolower(trim((string) ($input['status'] ?? ''))) === 'draft') {
            return true;
        }

        return filter_var($input['post_immediately'] ?? true, FILTER_VALIDATE_BOOLEAN) === false;
    }

    /**
     * @param array{product_id: int, stock_id: int, qty_delta: string} $line
     */
    private function assertSufficientQty(string $tenantId, array $line): void
    {
        $delta = (string) $line['qty_delta'];
        if (bccomp($delta, '0', 6) >= 0) {
            return;
        }

        $productId = (int) $line['product_id'];
        $stockId = (int) $line['stock_id'];
        $current = $this->balances->qtyForPair($tenantId, $productId, $stockId);
        $after = bcadd($current, $delta, 6);
        if (bccomp($after, '0', 6) < 0) {
            throw new \RuntimeException('insufficient_qty');
        }

        $available = $this->balances->availableQtyForPair($tenantId, $productId, $stockId);
        $afterAvailable = bcadd($available, $delta, 6);
        if (bccomp($afterAvailable, '0', 6) < 0) {
            throw new \RuntimeException('insufficient_qty');
        }
    }

    /**
     * @return array{ok: bool, status: int, row?: array, error?: string, code?: string}
     */
    private function requireProduct(array $session, int $productId): array
    {
        $row = $this->products->findVisibleById($session, $productId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            return ['ok' => false, 'status' => 404, 'error' => 'Товар не найден или не active', 'code' => 'product_not_found'];
        }
        if ((string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id']) {
            return ['ok' => false, 'status' => 403, 'error' => 'Движение только для сущностей своего tenant', 'code' => 'delegated_entity_read_only'];
        }

        return ['ok' => true, 'status' => 200, 'row' => $row];
    }

    /**
     * @return array{ok: bool, status: int, row?: array, error?: string, code?: string}
     */
    private function requireStock(array $session, int $stockId): array
    {
        $row = $this->stocks->findVisibleById($session, $stockId);
        if ($row === null || (string) ($row['status'] ?? '') !== 'active') {
            return ['ok' => false, 'status' => 404, 'error' => 'Складской узел не найден', 'code' => 'stock_not_found'];
        }
        if (!InventoryStockTypes::isAllowed((string) ($row['type'] ?? ''))) {
            return ['ok' => false, 'status' => 422, 'error' => 'Тип узла не для учёта остатков', 'code' => 'invalid_stock_type'];
        }
        if ((string) ($row['tenant_id'] ?? '') !== (string) $session['tenant_id']) {
            return ['ok' => false, 'status' => 403, 'error' => 'Движение только для сущностей своего tenant', 'code' => 'delegated_entity_read_only'];
        }

        return ['ok' => true, 'status' => 200, 'row' => $row];
    }

    private function normalizeQty(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) || is_int($raw) || is_float($raw)) {
            $s = trim((string) $raw);
            if ($s === '' || !is_numeric($s)) {
                return null;
            }

            return $s;
        }

        return null;
    }

    private function negateQty(string $qty): string
    {
        if (str_starts_with($qty, '-')) {
            return ltrim($qty, '-');
        }

        return '-' . $qty;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasDelegationShareInput(array $input): bool
    {
        foreach ([
            'delegation_share_tenant_ids', 'share_with_principal', 'share_with_managed',
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
}
