<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Repository;

use App\Database\Connection;

final class StockTypeRepository
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = Connection::get()->query(
            'SELECT code, name, name_en, description, allowed_parents_json, data_schema_json, sort_order, active
             FROM maniforge_wh_stock_types
             WHERE active = 1
             ORDER BY sort_order ASC, name ASC'
        );

        return array_map($this->mapRow(...), $stmt->fetchAll() ?: []);
    }

    public function findByCode(string $code): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT code, name, name_en, description, allowed_parents_json, data_schema_json, sort_order, active
             FROM maniforge_wh_stock_types WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => trim($code)]);
        $row = $stmt->fetch();

        return is_array($row) ? $this->mapRow($row) : null;
    }

    public function canBeChildOf(string $childType, ?string $parentType): bool
    {
        $type = $this->findByCode($childType);
        if ($type === null) {
            return false;
        }

        $allowed = $type['allowed_parents'] ?? [];
        if ($parentType === null || $parentType === '') {
            return $allowed === [];
        }

        return in_array($parentType, $allowed, true);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $allowedRaw = $row['allowed_parents_json'] ?? '[]';
        if (is_string($allowedRaw)) {
            $allowed = json_decode($allowedRaw, true);
        } else {
            $allowed = $allowedRaw;
        }

        $schemaRaw = $row['data_schema_json'] ?? '{}';
        if (is_string($schemaRaw)) {
            $schema = json_decode($schemaRaw, true);
        } else {
            $schema = $schemaRaw;
        }

        return [
            'code' => (string) ($row['code'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'name_en' => (string) ($row['name_en'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'allowed_parents' => is_array($allowed) ? $allowed : [],
            'data_schema' => is_array($schema) ? $schema : [],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'active' => (bool) ($row['active'] ?? true),
        ];
    }
}
