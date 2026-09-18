<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Security;

use App\Maniforge\Inventory\Support\MovementTypes;
use App\Maniforge\Wms\Repository\MarkingCodeRepository;
use App\Maniforge\Wms\Repository\PackUnitRepository;

/** Обновление статусов КИЗ/упаковок после движений (без Inventory). */
final class WmsMarkingLifecycle
{
    public function __construct(
        private readonly PackUnitRepository $packs = new PackUnitRepository(),
        private readonly MarkingCodeRepository $markings = new MarkingCodeRepository(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function afterMovementPosted(string $tenantId, string $type, array $lines, int $packUnitId): void
    {
        $markStatus = $type === MovementTypes::RECEIPT ? 'on_pallet' : 'available';
        if ($type === MovementTypes::ISSUE) {
            $markStatus = 'shipped';
        }

        foreach ($lines as $line) {
            $mid = (int) ($line['marking_code_id'] ?? 0);
            if ($mid > 0) {
                $this->markings->updateStatus(
                    $mid,
                    $tenantId,
                    $markStatus,
                    $packUnitId > 0 ? $packUnitId : null,
                    (int) ($line['stock_id'] ?? 0) ?: null
                );
            }
        }

        if ($packUnitId > 0) {
            $packStatus = $type === MovementTypes::RECEIPT ? 'at_stock' : 'shipped';
            $this->packs->update($packUnitId, $tenantId, ['status' => $packStatus]);
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    public function syncAfterMovementReversal(string $tenantId, string $originalMovementType, array $lines, ?int $packUnitId): void
    {
        $inverseType = $originalMovementType === MovementTypes::RECEIPT
            ? MovementTypes::ISSUE
            : MovementTypes::RECEIPT;
        $this->afterMovementPosted($tenantId, $inverseType, $lines, $packUnitId ?? 0);
    }
}
