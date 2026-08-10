<?php
/**
 * Раскладка как на нумпаде:
 * 7 8 9 ⌫
 * 4 5 6 C
 * 1 2 3
 *   0
 */
?>
<div class="calc-pad" role="group" aria-label="Цифровая клавиатура">
  <div class="calc-pad__row calc-pad__row--fn">
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="7">7</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="8">8</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="9">9</button>
    <button type="button" class="btn btn-light border calc-key calc-key--fn app-touch-btn" data-calc-action="del" aria-label="Удалить символ">
      <i class="bi bi-backspace-fill" aria-hidden="true"></i>
    </button>
  </div>
  <div class="calc-pad__row calc-pad__row--fn">
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="4">4</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="5">5</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="6">6</button>
    <button type="button" class="btn btn-light border calc-key calc-key--fn app-touch-btn" data-calc-action="erase" aria-label="Стереть всё">C</button>
  </div>
  <div class="calc-pad__row calc-pad__row--fn">
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="1">1</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="2">2</button>
    <button type="button" class="btn btn-light border calc-key app-touch-btn" data-calc-action="add" data-calc-value="3">3</button>
    <span class="calc-pad__spacer" aria-hidden="true"></span>
  </div>
  <div class="calc-pad__row calc-pad__row--fn calc-pad__row--zero">
    <button type="button" class="btn btn-light border calc-key calc-key--zero app-touch-btn" data-calc-action="add" data-calc-value="0">0</button>
    <span class="calc-pad__spacer" aria-hidden="true"></span>
  </div>
</div>
