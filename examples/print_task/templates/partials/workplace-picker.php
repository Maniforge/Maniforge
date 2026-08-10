<?php
/** @var array $ctx */
?>
<div class="workplace-picker">
  <p class="text-muted small mb-3">Выберите участок для продолжения</p>
  <div class="d-grid gap-2">
    <?php foreach ($ctx['work_places'] as $place): ?>
      <a href="<?= e($place['href']) ?>" class="btn btn-lg btn-outline-danger app-touch-btn">
        <?= e($place['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>
</div>
