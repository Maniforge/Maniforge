<?php
/** @var array $task */
/** @var bool $active */
?>
<div class="list-group-item task-list-item p-0 border-0 border-bottom <?= $active ? 'active' : '' ?>">
  <div class="d-flex align-items-stretch">
    <a href="<?= e($task['href']) ?>"
       class="task-list-item__link flex-grow-1 text-decoration-none <?= $active ? 'text-white' : 'text-body' ?> py-3 px-3">
      <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0">
          <div class="fw-semibold text-truncate"><?= e($task['fabric']) ?> / <?= e($task['rs_name']) ?></div>
          <div class="small <?= $active ? 'text-white-50' : 'text-muted' ?> text-truncate">
            <?= e($task['complect']) ?> · <?= e($task['color']) ?>
          </div>
          <div class="small mt-1 <?= $active ? 'text-white-50' : 'text-secondary' ?>"><?= e($task['rs_config']) ?></div>
        </div>
        <span class="badge rounded-pill <?= $active ? 'text-bg-light text-dark' : 'text-bg-primary' ?> fs-6">
          <?= (int) $task['count'] ?>
        </span>
      </div>
      <div class="d-flex justify-content-between mt-2 small <?= $active ? 'text-white-50' : 'text-muted' ?>">
        <span><?= e($task['stage']) ?></span>
        <span><?= e($task['date']) ?></span>
      </div>
      <div class="progress mt-2 rounded-pill" style="height: 5px;">
        <div class="progress-bar <?= $active ? 'bg-light' : '' ?>" style="width: <?= (int) $task['progress'] ?>%"></div>
      </div>
      <div class="small mt-1 <?= $active ? 'text-white-50' : 'text-muted' ?>"><?= e($task['progress_label']) ?></div>
    </a>
    <button type="button"
            class="task-order-info btn btn-link border-0 rounded-0 px-3 d-flex align-items-center <?= $active ? 'text-white-50' : 'text-secondary' ?>"
            data-order-nomer="<?= (int) $task['order_nomer'] ?>"
            aria-label="Информация о заказе">
      <i class="bi bi-info-circle fs-5"></i>
    </button>
  </div>
</div>
