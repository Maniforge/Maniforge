<?php

class TaskRepository
{
    private array $data;

    public function __construct()
    {
        $this->data = require APP_BASE . '/function/mock_data.php';
    }

    public function all(): array
    {
        return $this->data;
    }

    public function snmBoost(): array
    {
        return $this->data['snm_boost'];
    }

    public function workPlaces(): array
    {
        return $this->data['work_place'];
    }

    public function workInput(int $id): ?array
    {
        return $this->data['work_input'][$id] ?? null;
    }

    public function workInputs(): array
    {
        return $this->data['work_input'];
    }

    public function workGlobal(int $nomer): ?array
    {
        return $this->data['work_global'][$nomer] ?? null;
    }

    public function snmRs(int $id): ?array
    {
        return $this->data['snm_rs'][$id] ?? null;
    }

    public function rs(int $id): ?string
    {
        return $this->data['rs'][$id]['name'] ?? null;
    }

    public function color(int $id): ?array
    {
        return $this->data['colors'][$id] ?? null;
    }

    public function baseColorName(int $id): ?string
    {
        return $this->data['base_colors'][$id]['name'] ?? null;
    }

    public function complect(int $id): ?array
    {
        return $this->data['complects'][$id] ?? null;
    }

    public function complectName(int $id): ?string
    {
        return $this->data['complects'][$id]['name'] ?? null;
    }

    public function designName(int $id): ?string
    {
        return $this->data['design'][$id]['name'] ?? null;
    }

    public function materialUnit(int $id): ?string
    {
        return $this->data['materials'][$id]['unit'] ?? null;
    }

    public function sizeUnit(int $id): ?string
    {
        return $this->data['size'][$id]['unit'] ?? null;
    }

    public function workPlace(int $id): ?array
    {
        return $this->data['work_place'][$id] ?? null;
    }

    public function workOperation(int $id): ?string
    {
        return $this->data['work_operations'][$id]['name'] ?? null;
    }

    public function staffGroup(int $id): ?array
    {
        return $this->data['staff_group'][$id] ?? null;
    }

    public function personName(int $id): ?string
    {
        foreach ($this->data['people'] as $p) {
            if ((int) $p['id'] === $id) {
                return $p['name'];
            }
        }
        return null;
    }
}
