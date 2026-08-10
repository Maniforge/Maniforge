<?php
/** @var array $ctx */
/** @var array $detail */
/** @var array|null $calculatorForm */
$listHref = '/';
$backHref = url_with_params(['iwi' => $ctx['work_input_id'], 'iwp' => null]);
$showCalc = $ctx['show_calculator'] && $calculatorForm !== null;
?>
<article class="app-detail-panel card border-0 shadow-sm h-100">
  <div class="app-detail-panel__header card-header bg-danger text-white d-flex align-items-center gap-2 py-2 px-2 sticky-top">
    <a href="<?= e($showCalc ? $backHref : $listHref) ?>"
       class="btn btn-sm btn-outline-light border-0 app-touch-target"
       aria-label="Назад">
      <i class="bi bi-arrow-left fs-5"></i>
    </a>
    <div class="flex-grow-1 min-w-0">
      <div class="small text-white text-truncate lh-sm"><?= e($detail['title']) ?></div>
    </div>
    <span class="badge rounded-pill text-bg-success flex-shrink-0">
      <?= e($detail['status']) ?>
    </span>
  </div>

  <?php if (!$showCalc): ?>
  <div class="app-detail-panel__hero position-relative bg-dark">
    <img src="<?= e($detail['image']) ?>"
         class="w-100 app-detail-panel__img"
         alt=""
         onerror="this.src='https://placehold.co/400x160/352a2a/ffffff?text=Нет+фото'">
  </div>
  <?php endif; ?>

  <div class="app-detail-panel__body card-body p-3 <?= $showCalc ? 'pb-action-bar' : '' ?>">
    <?php if ($ctx['show_workplace_picker']): ?>
      <?php view('partials/workplace-picker', ['ctx' => $ctx]); ?>
    <?php elseif ($showCalc): ?>
      <?php view('partials/calculator', [
        'form' => $calculatorForm,
        'backHref' => $backHref,
      ]); ?>
    <?php endif; ?>
    <div id="calc-extra" class="mt-3 d-none"></div>
  </div>
</article>
