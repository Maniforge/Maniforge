<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Support;

use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Support\PublicUserPayload;

/** Подмешивает публичные профили создателя/редактора из maniforge_users. */
final class StockActorEnricher
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $stock
     * @return array<string, mixed>
     */
    public function enrichOne(array $stock, string $tenantId, string $subtenantId): array
    {
        return $this->enrichMany([$stock], $tenantId, $subtenantId)[0] ?? $stock;
    }

    /**
     * @param list<array<string, mixed>> $stocks
     * @return list<array<string, mixed>>
     */
    public function enrichMany(array $stocks, string $tenantId, string $subtenantId): array
    {
        if ($stocks === []) {
            return [];
        }

        $actorIds = [];
        foreach ($stocks as $stock) {
            if (isset($stock['created_by']) && $stock['created_by'] !== null) {
                $actorIds[(int) $stock['created_by']] = true;
            }
            if (isset($stock['updated_by']) && $stock['updated_by'] !== null) {
                $actorIds[(int) $stock['updated_by']] = true;
            }
        }

        $actors = $this->users->findManyByIdsInScope(array_keys($actorIds), $tenantId, $subtenantId);

        return array_map(function (array $stock) use ($actors): array {
            $createdBy = isset($stock['created_by']) ? (int) $stock['created_by'] : 0;
            $updatedBy = isset($stock['updated_by']) ? (int) $stock['updated_by'] : 0;
            if ($createdBy > 0 && isset($actors[$createdBy])) {
                $stock['created_by_user'] = PublicUserPayload::fromUser($actors[$createdBy]);
            }
            if ($updatedBy > 0 && isset($actors[$updatedBy])) {
                $stock['updated_by_user'] = PublicUserPayload::fromUser($actors[$updatedBy]);
            }

            return $stock;
        }, $stocks);
    }
}
