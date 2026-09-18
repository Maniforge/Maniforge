<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Maniforge\Wms\Repository\MarkingCodeRepository;
use App\Maniforge\Wms\Repository\PackContentRepository;
use App\Maniforge\Wms\Repository\PackUnitRepository;
use App\Maniforge\Wms\Support\PackUnitTypes;

/** Разворачивает дерево упаковок в строки движения (product, stock, qty, marking). */
final class PackTreeExploder
{
    public function __construct(
        private readonly PackUnitRepository $packs = new PackUnitRepository(),
        private readonly PackContentRepository $contents = new PackContentRepository(),
        private readonly MarkingCodeRepository $markings = new MarkingCodeRepository(),
    ) {
    }

    /**
     * @return array{ok: bool, status: int, lines?: list<array>, error?: string, code?: string}
     */
    public function explodeToMovementLines(
        string $tenantId,
        int $packUnitId,
        int $defaultStockId,
        string $movementType,
    ): array {
        $pack = $this->packs->findByIdInTenant($packUnitId, $tenantId);
        if ($pack === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Упаковка не найдена'];
        }
        if ((string) ($pack['status'] ?? '') !== 'sealed') {
            return ['ok' => false, 'status' => 422, 'error' => 'Упаковка должна быть sealed', 'code' => 'pack_not_sealed'];
        }

        $stockId = (int) ($pack['stock_id'] ?? $defaultStockId);
        if ($stockId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'stock_id обязателен для движения'];
        }

        $lines = [];
        $this->walk($tenantId, $packUnitId, $stockId, $movementType, $lines);

        if ($lines === []) {
            return ['ok' => false, 'status' => 422, 'error' => 'Пустая упаковка'];
        }

        return ['ok' => true, 'status' => 200, 'lines' => $lines];
    }

    /**
     * @param list<array> $lines
     */
    private function walk(string $tenantId, int $packId, int $stockId, string $movementType, array &$lines): void
    {
        foreach ($this->contents->listByParent($packId) as $row) {
            if (isset($row['marking_code_id']) && $row['marking_code_id'] !== null) {
                $markingId = (int) $row['marking_code_id'];
                $marking = $this->markings->findByIdInTenant($markingId, $tenantId);
                if ($marking === null) {
                    continue;
                }
                $qty = $movementType === 'issue' ? '-1' : '1';
                $lines[] = [
                    'product_id' => (int) $marking['product_id'],
                    'stock_id' => $stockId,
                    'qty_delta' => $qty,
                    'marking_code_id' => $markingId,
                    'pack_unit_id' => $packId,
                ];
                continue;
            }

            if (isset($row['child_pack_unit_id']) && $row['child_pack_unit_id'] !== null) {
                $childId = (int) $row['child_pack_unit_id'];
                $child = $this->packs->findByIdInTenant($childId, $tenantId);
                if ($child === null) {
                    continue;
                }
                if (in_array((string) ($child['unit_type'] ?? ''), [PackUnitTypes::GROUP, PackUnitTypes::CONSUMER], true)) {
                    $this->walk($tenantId, $childId, $stockId, $movementType, $lines);
                } else {
                    $lines[] = [
                        'product_id' => (int) ($child['product_id'] ?? 0),
                        'stock_id' => $stockId,
                        'qty_delta' => (string) ($row['qty'] ?? '1'),
                        'pack_unit_id' => $childId,
                    ];
                }
            }
        }
    }
}
