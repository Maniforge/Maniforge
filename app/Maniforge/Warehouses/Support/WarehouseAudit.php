<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Support;

use App\Maniforge\Rbac\Repository\AuditLogRepository;

/** События модуля в едином контуре maniforge_audit_log. */
final class WarehouseAudit
{
    public const STOCK_CREATED = 'warehouses.stock.created';
    public const STOCK_UPDATED = 'warehouses.stock.updated';
    public const STOCK_ARCHIVED = 'warehouses.stock.archived';
    public const STOCK_EXTERNAL_BOUND = 'warehouses.stock.external_bound';

    public function __construct(
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function stockCreated(array $session, int $stockId, string $code, string $type, array $extra = []): void
    {
        $this->write($session, self::STOCK_CREATED, $stockId, array_merge([
            'code' => $code,
            'type' => $type,
        ], $extra));
    }

    /**
     * @param array<string, mixed> $diff поля {field: {from, to}}
     * @param array<string, mixed> $extra
     */
    public function stockUpdated(array $session, int $stockId, array $diff, array $extra = []): void
    {
        $this->write($session, self::STOCK_UPDATED, $stockId, array_merge([
            'diff' => $diff,
        ], $extra));
    }

    public function stockArchived(array $session, int $stockId, string $code): void
    {
        $this->write($session, self::STOCK_ARCHIVED, $stockId, ['code' => $code]);
    }

    public function stockExternalBound(array $session, int $stockId, string $externalType, string $externalId): void
    {
        $this->write($session, self::STOCK_EXTERNAL_BOUND, $stockId, [
            'external_type' => $externalType,
            'external_id' => $externalId,
        ]);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after, array $fields): array
    {
        $diff = [];
        foreach ($fields as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ($from === $to) {
                continue;
            }
            if (is_array($from) && is_array($to) && json_encode($from) === json_encode($to)) {
                continue;
            }
            $diff[$field] = ['from' => $from, 'to' => $to];
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $payload
     */
    private function write(array $session, string $eventType, int $stockId, array $payload): void
    {
        $this->audit->write(
            $eventType,
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            array_merge(['stock_id' => $stockId], $payload)
        );
    }
}
