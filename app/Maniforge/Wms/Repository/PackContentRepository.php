<?php
declare(strict_types=1);

namespace App\Maniforge\Wms\Repository;

use App\Database\Connection;

final class PackContentRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listByParent(int $parentPackId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT c.id, c.parent_pack_unit_id, c.line_no, c.child_pack_unit_id, c.marking_code_id, c.qty,
                    cp.code AS child_pack_code, cp.unit_type AS child_pack_type,
                    m.code_full AS marking_code, m.product_id AS marking_product_id
             FROM maniforge_wms_pack_contents c
             LEFT JOIN maniforge_wms_pack_units cp ON cp.id = c.child_pack_unit_id
             LEFT JOIN maniforge_wms_marking_codes m ON m.id = c.marking_code_id
             WHERE c.parent_pack_unit_id = :parent_id
             ORDER BY c.line_no ASC'
        );
        $stmt->execute([':parent_id' => $parentPackId]);

        return $stmt->fetchAll() ?: [];
    }

    public function addMarking(int $parentPackId, int $markingCodeId, int $lineNo, string $qty = '1'): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_wms_pack_contents (parent_pack_unit_id, line_no, marking_code_id, qty)
             VALUES (:parent_id, :line_no, :marking_id, :qty)'
        );
        $stmt->execute([
            ':parent_id' => $parentPackId,
            ':line_no' => $lineNo,
            ':marking_id' => $markingCodeId,
            ':qty' => $qty,
        ]);
    }

    public function addChildPack(int $parentPackId, int $childPackId, int $lineNo, string $qty = '1'): void
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_wms_pack_contents (parent_pack_unit_id, line_no, child_pack_unit_id, qty)
             VALUES (:parent_id, :line_no, :child_id, :qty)'
        );
        $stmt->execute([
            ':parent_id' => $parentPackId,
            ':line_no' => $lineNo,
            ':child_id' => $childPackId,
            ':qty' => $qty,
        ]);
    }

    public function countByParent(int $parentPackId): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(*) FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id = :parent_id'
        );
        $stmt->execute([':parent_id' => $parentPackId]);

        return (int) $stmt->fetchColumn();
    }

    public function deleteByParent(int $parentPackId): void
    {
        $stmt = Connection::get()->prepare(
            'DELETE FROM maniforge_wms_pack_contents WHERE parent_pack_unit_id = :parent_id'
        );
        $stmt->execute([':parent_id' => $parentPackId]);
    }
}
