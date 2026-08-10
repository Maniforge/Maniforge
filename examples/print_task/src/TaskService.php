<?php

class TaskService
{
  private const USER_WORK_PLACES = [6, 7];

  public function __construct(private TaskRepository $repo) {}

  public function getContext(?string $workInputId, ?string $workPlaceId): array
  {
    $workInputId = $this->normalizeWorkInputId($workInputId);
    $workPlaceId = $this->normalizeWorkPlaceId($workPlaceId, $workInputId);

    return [
      'user' => ['id' => 1, 'role' => 'print-masters'],
      'work_input_id' => $workInputId,
      'work_place_id' => $workPlaceId,
      'work_places' => $this->getAccessibleWorkPlaces($workInputId),
      'show_workplace_picker' => $workInputId !== null && $workPlaceId === null,
      'show_calculator' => $workInputId !== null && $workPlaceId !== null,
    ];
  }

  /** @return list<array<string, mixed>> */
  public function getTaskCards(): array
  {
    $cards = [];
    $snmBoost = $this->repo->snmBoost();

    foreach ($this->repo->workInputs() as $wi) {
      if ($wi['table_input'] !== 'snm_rs') {
        continue;
      }
      $snmRs = $this->repo->snmRs((int) $wi['nomer_input']);
      if ($snmRs === null) {
        continue;
      }
      $boost = $snmBoost[$snmRs['snm_in']] ?? null;
      if ($boost === null) {
        continue;
      }
      $color = $this->repo->color((int) $snmRs['id_colors']);
      if ($color === null) {
        continue;
      }

      $cards[] = [
        'work_input_id' => $wi['id'],
        'order_nomer' => $wi['nomer_work_global'],
        'fabric' => $boost['name'],
        'rs_name' => $this->repo->rs((int) $snmRs['srs_in']) ?? '—',
        'count' => $wi['count'],
        'complect' => $this->repo->complectName((int) $color['id_complect']) ?? '—',
        'color' => $this->repo->baseColorName((int) $color['main_color']) ?? '—',
        'rs_config' => rs_config_label((int) $snmRs['rs_config'], $this->repo->rs((int) $snmRs['srs_in']) ?? ''),
        'stage' => 'Печать',
        'date' => '18.06.2026',
        'shift' => 'ЗП-1001',
        'time' => '10:30',
        'progress' => 48,
        'progress_label' => '12/25 (48%)',
        'href' => url_with_params(['iwi' => $wi['id']]),
      ];
    }

    return $cards;
  }

  public function getTaskDetail(int $workInputId): ?array
  {
    $wi = $this->repo->workInput($workInputId);
    if ($wi === null || $wi['table_input'] !== 'snm_rs') {
      return null;
    }

    $snmRs = $this->repo->snmRs((int) $wi['nomer_input']);
    if ($snmRs === null) {
      return null;
    }

    $boost = $this->repo->snmBoost()[$snmRs['snm_in']] ?? null;
    $color = $this->repo->color((int) $snmRs['id_colors']);
    if ($boost === null || $color === null) {
      return null;
    }

    $design = $this->resolveDesign($color, (int) $snmRs['rs_config']);
    $unitSize = $this->repo->sizeUnit((int) $boost['size']) ?? '';
    $unitMat = $this->repo->materialUnit((int) $boost['material']) ?? '';

    $title = sprintf(
      '%s_%s-%s-%s%s (%s/%s)',
      $design['name'],
      $design['id'],
      $unitSize,
      $unitMat,
      $design['cloth_code'],
      $snmRs['snm_ext'],
      $snmRs['srs_ext']
    );

    $img = $snmRs['img'] ?? '';
    $imgPath = ($img !== '')
      ? '/collections/' . $img
      : sprintf('/complects/complects_img/%d_%d_%d.png', $color['id_complect'], $color['main_color'], $snmRs['rs_config']);

    return [
      'title' => $title,
      'image' => $imgPath,
      'status' => 'В работе',
    ];
  }

  public function getCalculatorForm(
    int $workPlaceId,
    int $workInputId,
    ?int $selectedOperationId = null,
    ?int $selectedStaffGroupId = null,
  ): ?array {
    $wp = $this->repo->workPlace($workPlaceId);
    if ($wp === null) {
      return null;
    }

    $operationOptions = $this->buildOperationOptions($wp['list_idwo']);
    $executorOptions = $this->buildExecutorOptions($wp['list_idsg']);

    $operationId = $this->resolveSelectedId($selectedOperationId, $operationOptions);
    $staffGroupId = $this->resolveSelectedId($selectedStaffGroupId, $executorOptions);

    $workplaceOptions = [];
    foreach ($this->getAccessibleWorkPlaces($workInputId) as $place) {
      $workplaceOptions[] = [
        'id' => $place['id'],
        'label' => $place['name'],
        'href' => $place['href'],
      ];
    }

    return [
      'work_input_id' => $workInputId,
      'work_place_id' => $workPlaceId,
      'pickers' => [
        [
          'key' => 'workplace',
          'label' => 'Участок',
          'type' => 'navigate',
          'selected_id' => $workPlaceId,
          'selected_label' => $wp['name'],
          'options' => $workplaceOptions,
        ],
        [
          'key' => 'operation',
          'label' => 'Операция',
          'type' => 'select',
          'selected_id' => $operationId,
          'selected_label' => $this->labelById($operationOptions, $operationId) ?? 'Выберите операцию',
          'options' => $operationOptions,
        ],
        [
          'key' => 'executors',
          'label' => 'Исполнители',
          'type' => 'select',
          'selected_id' => $staffGroupId,
          'selected_label' => $this->labelById($executorOptions, $staffGroupId) ?? 'Выберите исполнителей',
          'options' => $executorOptions,
        ],
      ],
    ];
  }

  /** @deprecated use getCalculatorForm */
  public function getWorkplaceMenu(int $workPlaceId): ?array
  {
    $form = $this->getCalculatorForm($workPlaceId, 0);
    if ($form === null) {
      return null;
    }
    return array_map(static fn($p) => [
      'caption' => $p['label'],
      'value' => $p['selected_label'],
    ], $form['pickers']);
  }

  /** @return list<list<array{id: string, action: string}>> */
  public function getCalculatorLayout(): array
  {
    return [
      [
        ['id' => '1', 'action' => 'add'],
        ['id' => '2', 'action' => 'add'],
        ['id' => '3', 'action' => 'add'],
        ['id' => 'del', 'action' => 'del'],
      ],
      [
        ['id' => '4', 'action' => 'add'],
        ['id' => '5', 'action' => 'add'],
        ['id' => '6', 'action' => 'add'],
        ['id' => 'erase', 'action' => 'erase'],
      ],
      [
        ['id' => '7', 'action' => 'add'],
        ['id' => '8', 'action' => 'add'],
        ['id' => '9', 'action' => 'add'],
        ['id' => '0', 'action' => 'add'],
      ],
    ];
  }

  public function getCalculatorFragmentData(int $workPlaceId = 6): array
  {
    return $this->getCalculatorForm($workPlaceId, 501) ?? ['pickers' => []];
  }

  private function normalizeWorkInputId(?string $id): ?int
  {
    if ($id === null || $id === '') {
      return null;
    }
    $wi = $this->repo->workInput((int) $id);
    if ($wi === null || $wi['table_input'] !== 'snm_rs') {
      return null;
    }
    $snmRs = $this->repo->snmRs((int) $wi['nomer_input']);
    if ($snmRs === null || !isset($this->repo->snmBoost()[$snmRs['snm_in']])) {
      return null;
    }
    return (int) $id;
  }

  private function normalizeWorkPlaceId(?string $id, ?int $workInputId): ?int
  {
    if ($workInputId === null) {
      return null;
    }
    $allowed = self::USER_WORK_PLACES;
    if ($id === null || $id === '') {
      return count($allowed) === 1 ? $allowed[0] : null;
    }
    $wpId = (int) $id;
    return in_array($wpId, $allowed, true) ? $wpId : null;
  }

  /** @return list<array{id: int, name: string, href: string}> */
  private function getAccessibleWorkPlaces(?int $workInputId = null): array
  {
    $places = [];
    foreach (self::USER_WORK_PLACES as $id) {
      $wp = $this->repo->workPlace($id);
      if ($wp === null) {
        continue;
      }
      $params = [];
      if ($workInputId !== null) {
        $params['iwi'] = $workInputId;
      }
      $params['iwp'] = $id;
      $places[] = [
        'id' => $id,
        'name' => $wp['name'],
        'href' => url_with_params($params),
      ];
    }
    return $places;
  }

  /** @return list<array{id: int, label: string}> */
  private function buildOperationOptions(string $listIdwo): array
  {
    $options = [];
    foreach (array_map('trim', explode(',', $listIdwo)) as $rawId) {
      if ($rawId === '') {
        continue;
      }
      $id = (int) $rawId;
      $name = $this->repo->workOperation($id);
      if ($name !== null) {
        $options[] = ['id' => $id, 'label' => $name];
      }
    }
    return $options;
  }

  /** @return list<array{id: int, label: string, subtitle: string}> */
  private function buildExecutorOptions(string $listIdsg): array
  {
    $options = [];
    foreach (array_map('trim', explode(',', $listIdsg)) as $rawId) {
      if ($rawId === '') {
        continue;
      }
      $sgId = (int) $rawId;
      $sg = $this->repo->staffGroup($sgId);
      if ($sg === null) {
        continue;
      }
      $people = [];
      foreach (array_map('trim', explode(',', $sg['list'])) as $pid) {
        $name = $this->repo->personName((int) $pid);
        if ($name !== null) {
          $people[] = $name;
        }
      }
      $options[] = [
        'id' => $sgId,
        'label' => implode(', ', $people) ?: 'Группа #' . $sgId,
        'subtitle' => count($people) . ' чел.',
      ];
    }
    return $options;
  }

  /** @param list<array{id: int, label: string}> $options */
  private function resolveSelectedId(?int $selectedId, array $options): ?int
  {
    if ($options === []) {
      return null;
    }
    if ($selectedId !== null) {
      foreach ($options as $opt) {
        if ((int) $opt['id'] === $selectedId) {
          return $selectedId;
        }
      }
    }
    return (int) $options[0]['id'];
  }

  /** @param list<array{id: int, label: string}> $options */
  private function labelById(array $options, ?int $id): ?string
  {
    if ($id === null) {
      return null;
    }
    foreach ($options as $opt) {
      if ((int) $opt['id'] === $id) {
        return $opt['label'];
      }
    }
    return null;
  }

  /** @return array{id: int|string, name: string, cloth_code: string} */
  private function resolveDesign(array $color, int $rsConfig): array
  {
    $complect = $this->repo->complect((int) $color['id_complect']);
    $designInput = $complect['design_input'] ?? '';
    $clothParts = explode(', ', $color['cloth_c'] ?? '');
    $designId = 0;
    $clothCode = '';

    foreach (explode(', ', $designInput) as $i => $pair) {
      [$dId, $sost] = explode(' / ', $pair);
      if ((int) $sost === $rsConfig) {
        $designId = (int) $dId;
        $clothCode = $clothParts[$i] ?? '';
        break;
      }
    }

    return [
      'id' => $designId,
      'name' => $this->repo->designName($designId) ?? 'Дизайн',
      'cloth_code' => $clothCode,
    ];
  }
}
