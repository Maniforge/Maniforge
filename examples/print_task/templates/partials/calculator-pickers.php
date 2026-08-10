<?php
/** @var array $form */
$pickers = $form['pickers'] ?? [];
?>
<div class="calc-fields">
  <input type="hidden" id="picker-workplace-id" value="<?= (int) ($form['work_place_id'] ?? 0) ?>">
  <input type="hidden" id="picker-operation-id" value="">
  <input type="hidden" id="picker-executors-id" value="">

  <?php foreach ($pickers as $picker): ?>
    <div class="mb-2">
      <div class="calc-field-label"><?= e($picker['label']) ?></div>
      <button type="button"
              class="calc-picker w-100"
              data-picker-key="<?= e($picker['key']) ?>"
              data-picker-type="<?= e($picker['type']) ?>"
              data-selected-id="<?= (int) ($picker['selected_id'] ?? 0) ?>"
              aria-haspopup="dialog"
              aria-expanded="false">
        <span class="calc-picker__value" data-picker-display="<?= e($picker['key']) ?>">
          <?= e($picker['selected_label'] ?? 'Выберите…') ?>
        </span>
        <i class="bi bi-chevron-down calc-picker__icon" aria-hidden="true"></i>
      </button>
    </div>
  <?php endforeach; ?>

  <div class="mb-3 mt-2">
    <div class="calc-field-label" id="calc-display-label">Введите количество</div>
    <div id="calc-display" class="calc-display" role="textbox" aria-labelledby="calc-display-label" aria-readonly="true">0</div>
  </div>
</div>

<script type="application/json" id="calc-picker-data"><?=
  json_encode(['pickers' => $pickers], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
?></script>
