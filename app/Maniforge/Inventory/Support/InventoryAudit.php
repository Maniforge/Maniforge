<?php
declare(strict_types=1);

namespace App\Maniforge\Inventory\Support;

use App\Maniforge\Rbac\Repository\AuditLogRepository;

final class InventoryAudit
{
    public const MOVEMENT_POSTED = 'inventory.movement.posted';

    public function __construct(
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function movementPosted(array $session, int $movementId, string $docNumber, string $type, array $payload = []): void
    {
        $this->audit->write(
            self::MOVEMENT_POSTED,
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            array_merge([
                'movement_id' => $movementId,
                'doc_number' => $docNumber,
                'movement_type' => $type,
            ], $payload)
        );
    }
}
