<?php
/** @var array $form */
/** @var string $backHref */
$listHref = '/';
?>
<div class="calculator" id="calculator-app">
  <div class="d-flex justify-content-between align-items-center mb-2 small">
    <a href="<?= e($listHref) ?>" class="text-decoration-none text-muted">
      <i class="bi bi-list-ul me-1"></i>Список
    </a>
    <span class="text-muted">Калькулятор</span>
  </div>

  <?php view('partials/calculator-pickers', ['form' => $form]); ?>
  <?php view('partials/calculator-pad'); ?>
  <?php view('partials/picker-offcanvas'); ?>
</div>

<nav class="app-action-bar" aria-label="Действия">
  <a href="<?= e($backHref) ?>" class="btn btn-outline-secondary app-touch-btn">Назад</a>
  <button type="button" class="btn btn-success flex-grow-1 app-touch-btn" id="calc-confirm">Подтвердить</button>
</nav>
