<?php
declare(strict_types=1);

namespace App\Maniforge\Products\Support;

use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Support\PublicUserPayload;

final class ProductActorEnricher
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function enrichMany(array $rows, string $tenantId, string $subtenantId): array
    {
        if ($rows === []) {
            return [];
        }

        $actorIds = [];
        foreach ($rows as $row) {
            if (isset($row['created_by']) && $row['created_by'] !== null) {
                $actorIds[(int) $row['created_by']] = true;
            }
            if (isset($row['updated_by']) && $row['updated_by'] !== null) {
                $actorIds[(int) $row['updated_by']] = true;
            }
        }

        $actors = $this->users->findManyByIdsInScope(array_keys($actorIds), $tenantId, $subtenantId);

        return array_map(function (array $row) use ($actors): array {
            $createdBy = isset($row['created_by']) ? (int) $row['created_by'] : 0;
            $updatedBy = isset($row['updated_by']) ? (int) $row['updated_by'] : 0;
            if ($createdBy > 0 && isset($actors[$createdBy])) {
                $row['created_by_user'] = PublicUserPayload::fromUser($actors[$createdBy]);
            }
            if ($updatedBy > 0 && isset($actors[$updatedBy])) {
                $row['updated_by_user'] = PublicUserPayload::fromUser($actors[$updatedBy]);
            }

            return $row;
        }, $rows);
    }
}
