<?php
/** @var array $data */
?>
<div class="modal fade" id="orderInfoModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-fullscreen-sm-down modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header sticky-top bg-white">
        <h5 class="modal-title fs-6 text-truncate me-2"><?= e($data['title']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
      </div>
      <div class="modal-body px-3">
        <?php foreach ($data['groups'] as $group): ?>
          <h6 class="text-muted small text-uppercase border-bottom pb-2 mb-3"><?= e($group['title']) ?></h6>
          <?php foreach ($group['items'] as $item): ?>
            <div class="mb-3 pb-3 border-bottom">
              <div class="d-flex align-items-start gap-2 mb-2">
                <span class="badge text-bg-secondary rounded-pill"><?= e((string) $item['count']) ?></span>
                <div class="min-w-0 flex-grow-1">
                  <div class="fw-medium"><?= e($item['name']) ?></div>
                  <div class="small text-muted"><?= e($item['stage']) ?></div>
                </div>
              </div>
              <div class="progress rounded-pill" style="height: 1.25rem;">
                <div class="progress-bar" style="width: <?= (int) $item['progress'] ?>%">
                  <span class="small"><?= e($item['progress_label']) ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
        <?php if ($data['groups'] === []): ?>
          <p class="text-muted mb-0 text-center py-4">Нет позиций в заказе</p>
        <?php endif; ?>
      </div>
      <div class="modal-footer sticky-bottom bg-white border-top gap-2">
        <button type="button" class="btn btn-outline-secondary flex-grow-1 app-touch-btn" data-bs-dismiss="modal">Назад</button>
        <button type="button" class="btn btn-success flex-grow-1 app-touch-btn" disabled>Отправить</button>
      </div>
    </div>
  </div>
</div>
