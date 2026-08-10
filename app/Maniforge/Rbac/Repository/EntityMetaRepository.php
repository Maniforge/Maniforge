<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Security\EntityMetaTypes;

final class EntityMetaRepository
{
    public function bind(
        string $type,
        string $meta,
        int $iIndex,
        int $iId,
        string $tenantId = '',
        string $subtenantId = '',
        ?int $oIndex = null,
        ?string $oRef = null,
    ): void {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_entity_meta (
                tenant_id, subtenant_id, meta, type, i_index, i_id, o_index, o_ref
            ) VALUES (
                :tenant_id, :subtenant_id, :meta, :type, :i_index, :i_id, :o_index, :o_ref
            )
            ON DUPLICATE KEY UPDATE
                i_id = VALUES(i_id),
                o_index = VALUES(o_index),
                o_ref = VALUES(o_ref)'
        );
        $stmt->execute([
            ':tenant_id' => strtolower(trim($tenantId)),
            ':subtenant_id' => strtolower(trim($subtenantId)),
            ':meta' => trim($meta),
            ':type' => trim($type),
            ':i_index' => $iIndex,
            ':i_id' => $iId,
            ':o_index' => $oIndex,
            ':o_ref' => $oRef !== null && trim($oRef) !== '' ? trim($oRef) : null,
        ]);
    }

    public function findInScope(
        string $type,
        string $meta,
        int $iIndex,
        string $tenantId,
        string $subtenantId,
    ): ?array {
        $stmt = Connection::get()->prepare(
            'SELECT *
             FROM maniforge_entity_meta
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND type = :type
               AND meta = :meta
               AND i_index = :i_index
             LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => strtolower(trim($tenantId)),
            ':subtenant_id' => strtolower(trim($subtenantId)),
            ':type' => trim($type),
            ':meta' => trim($meta),
            ':i_index' => $iIndex,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<int>
     */
    public function internalIdsByMeta(string $type, string $meta, int $iIndex): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT i_id
             FROM maniforge_entity_meta
             WHERE type = :type AND meta = :meta AND i_index = :i_index
             ORDER BY id ASC'
        );
        $stmt->execute([
            ':type' => trim($type),
            ':meta' => trim($meta),
            ':i_index' => $iIndex,
        ]);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ids[] = (int) ($row['i_id'] ?? 0);
        }

        return array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    }

    public function rebindPhoneForUser(
        string $phone,
        int $userId,
        string $tenantId,
        string $subtenantId,
    ): void {
        $this->bind(
            EntityMetaTypes::TYPE_PHONE,
            $phone,
            EntityMetaTypes::I_USER,
            $userId,
            $tenantId,
            $subtenantId,
            EntityMetaTypes::O_PHONE,
            $phone,
        );
        $this->bindGlobalPhone($phone, $userId);
    }

    public function bindGlobalPhone(string $phone, int $userId): void
    {
        $this->bind(
            EntityMetaTypes::TYPE_PHONE,
            $phone,
            EntityMetaTypes::I_USER,
            $userId,
            EntityMetaTypes::SCOPE_GLOBAL_TENANT,
            EntityMetaTypes::SCOPE_GLOBAL_SUBTENANT,
            EntityMetaTypes::O_PHONE,
            $phone,
        );
    }

    public function findGlobalPhoneUserId(string $phone): ?int
    {
        $row = $this->findInScope(
            EntityMetaTypes::TYPE_PHONE,
            $phone,
            EntityMetaTypes::I_USER,
            EntityMetaTypes::SCOPE_GLOBAL_TENANT,
            EntityMetaTypes::SCOPE_GLOBAL_SUBTENANT,
        );
        if ($row === null) {
            return null;
        }

        $userId = (int) ($row['i_id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }
}
