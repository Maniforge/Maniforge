<?php
/** @var array $ctx */
/** @var list<array> $tasks */
/** @var array|null $detail */
/** @var array|null $calculatorForm */
$hasDetail = $detail !== null;
$showCalc = $hasDetail && ($ctx['show_calculator'] ?? false);
?>
<div class="app-tasks<?= $hasDetail ? ' app-tasks--detail' : '' ?><?= $showCalc ? ' app-tasks--calc' : '' ?>">

  <aside class="app-tasks__list" aria-label="Список заданий">
    <div class="app-list-card card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <h1 class="h6 mb-0 fw-semibold">Задания</h1>
        <span class="badge rounded-pill text-bg-secondary"><?= count($tasks) ?></span>
      </div>
      <div class="list-group list-group-flush task-list">
        <?php if ($tasks === []): ?>
          <div class="list-group-item text-muted py-4 text-center">Нет заданий</div>
        <?php endif; ?>
        <?php foreach ($tasks as $task): ?>
          <?php view('partials/task-list-item', [
            'task' => $task,
            'active' => (int) ($ctx['work_input_id'] ?? 0) === (int) $task['work_input_id'],
          ]); ?>
        <?php endforeach; ?>
      </div>
    </div>
  </aside>

  <section class="app-tasks__detail" aria-label="Карточка задания">
    <?php if (!$hasDetail): ?>
      <div class="app-empty card border-0 shadow-sm h-100 d-none d-lg-flex">
        <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted text-center px-4">
          <i class="bi bi-hand-index-thumb display-4 mb-3 opacity-50"></i>
          <p class="mb-0">Выберите задание из списка</p>
        </div>
      </div>
    <?php else: ?>
      <?php view('partials/task-panel', [
        'ctx' => $ctx,
        'detail' => $detail,
        'calculatorForm' => $calculatorForm,
      ]); ?>
    <?php endif; ?>
  </section>

</div>
