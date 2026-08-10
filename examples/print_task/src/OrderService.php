<?php

class OrderService
{
  public function __construct(private TaskRepository $repo) {}

  public function getOrderModalData(int $orderNomer): ?array
  {
    $order = $this->repo->workGlobal($orderNomer);
    if ($order === null) {
      $order = [
        'nomer' => $orderNomer,
        'name' => 'Тестовый заказ #' . $orderNomer,
        'createdAt' => date('Y-m-d H:i:s'),
      ];
    }

    $groups = [
      ['title' => 'План этапа', 'items' => []],
      ['title' => 'Выпуск продукции', 'items' => []],
      ['title' => 'Печать ткани', 'items' => []],
    ];

    foreach ($this->repo->workInputs() as $wi) {
      if ((int) $wi['nomer_work_global'] !== $orderNomer) {
        continue;
      }

      if ($wi['table_input'] === 'j_et') {
        $groups[0]['items'][] = [
          'name' => 'Этап №' . $wi['nomer_input'],
          'count' => $wi['count'],
          'stage' => 'Печать',
          'progress' => 0,
          'progress_label' => '0%',
        ];
        continue;
      }

      if ($wi['table_input'] !== 'snm_rs') {
        continue;
      }

      $snmRs = $this->repo->snmRs((int) $wi['nomer_input']);
      if ($snmRs === null) {
        continue;
      }

      $boost = $this->repo->snmBoost()[$snmRs['snm_in']] ?? null;
      if ($boost === null) {
        continue;
      }

      $name = $boost['name'];
      $rsName = $this->repo->rs((int) $snmRs['srs_in']);
      if ($rsName !== null && (int) $snmRs['srs_in'] > 1) {
        $name .= ' / ' . $rsName;
      }

      $groupIdx = ($boost['product_type'] ?? 0) === 8 ? 2 : 1;
      $base = max(1, (int) $wi['count']);
      $done = (int) round($base * 0.48);

      $groups[$groupIdx]['items'][] = [
        'name' => $name,
        'count' => $wi['count'],
        'stage' => $groupIdx === 2 ? 'Печать' : 'Регистрация товара',
        'progress' => (int) round(($done / $base) * 100),
        'progress_label' => $done . '/' . $base . ' (' . (int) round(($done / $base) * 100) . '%)',
      ];
    }

    return [
      'title' => '№' . $orderNomer . ' ' . $order['name'],
      'groups' => array_values(array_filter($groups, static fn($g) => $g['items'] !== [])),
    ];
  }
}
