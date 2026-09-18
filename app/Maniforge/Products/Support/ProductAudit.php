<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Support;

use App\Maniforge\Rbac\Repository\AuditLogRepository;

final class ProductAudit
{
    public const CREATED = 'products.product.created';
    public const UPDATED = 'products.product.updated';
    public const ARCHIVED = 'products.product.archived';

    public function __construct(
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function created(array $session, int $productId, string $code, array $extra = []): void
    {
        $this->write($session, self::CREATED, $productId, array_merge(['code' => $code], $extra));
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $diff
     */
    public function updated(array $session, int $productId, array $diff, array $extra = []): void
    {
        $this->write($session, self::UPDATED, $productId, array_merge(['diff' => $diff], $extra));
    }

    public function archived(array $session, int $productId, string $code): void
    {
        $this->write($session, self::ARCHIVED, $productId, ['code' => $code]);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public static function diff(array $before, array $after, array $keys): array
    {
        $diff = [];
        foreach ($keys as $key) {
            $from = $before[$key] ?? null;
            $to = $after[$key] ?? null;
            if ($from !== $to) {
                $diff[$key] = ['from' => $from, 'to' => $to];
            }
        }

        return $diff;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(array $session, string $event, int $productId, array $payload): void
    {
        $this->audit->write(
            $event,
            (int) $session['user_id'],
            (string) $session['tenant_id'],
            (string) $session['subtenant_id'],
            array_merge(['product_id' => $productId], $payload)
        );
    }
}
