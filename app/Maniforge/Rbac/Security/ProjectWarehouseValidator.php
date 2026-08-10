<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Warehouses\Repository\StockRepository;
use App\Maniforge\Warehouses\Support\StockTypeCatalog;

/** Проверка привязки проекта к корневому складу (type=warehouse) в видимом scope. */
final class ProjectWarehouseValidator
{
    public function __construct(
        private readonly StockRepository $stocks = new StockRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $projectRow
     * @return array{ok: bool, status: int, error?: string}
     */
    public function validate(int $warehouseId, array $session, array $projectRow): array
    {
        if ($warehouseId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'warehouse_id должен быть положительным числом'];
        }

        $checkSession = $session;
        $projectId = (int) ($projectRow['id'] ?? 0);
        if ($projectId > 0) {
            $checkSession['project_id'] = $projectId;
        }

        $stock = $this->stocks->findVisibleById($checkSession, $warehouseId);
        if ($stock === null) {
            return ['ok' => false, 'status' => 422, 'error' => 'Склад не найден в scope проекта'];
        }

        if ((string) ($stock['type'] ?? '') !== StockTypeCatalog::WAREHOUSE) {
            return ['ok' => false, 'status' => 422, 'error' => 'warehouse_id должен указывать на узел типа warehouse'];
        }

        if ((string) ($stock['status'] ?? '') !== 'active') {
            return ['ok' => false, 'status' => 422, 'error' => 'Склад должен быть в статусе active'];
        }

        return ['ok' => true, 'status' => 200];
    }

    /**
     * @param array<string, mixed> $input
     */
    public function hasWarehouseField(array $input): bool
    {
        return array_key_exists('warehouse_id', $input) || array_key_exists('warehouseId', $input);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{ok: bool, status: int, warehouse_id?: int|null, error?: string, provided: bool}
     */
    public function parseWarehouseId(array $input): array
    {
        if (!$this->hasWarehouseField($input)) {
            return ['ok' => true, 'status' => 200, 'provided' => false];
        }

        $raw = $input['warehouse_id'] ?? $input['warehouseId'];
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return ['ok' => true, 'status' => 200, 'warehouse_id' => null, 'provided' => true];
        }

        if (!is_int($raw) && !is_string($raw) && !is_float($raw)) {
            return ['ok' => false, 'status' => 422, 'error' => 'warehouse_id должен быть числом или null', 'provided' => true];
        }

        $warehouseId = (int) $raw;
        if ($warehouseId <= 0) {
            return ['ok' => false, 'status' => 422, 'error' => 'warehouse_id должен быть положительным числом', 'provided' => true];
        }

        return ['ok' => true, 'status' => 200, 'warehouse_id' => $warehouseId, 'provided' => true];
    }
}
