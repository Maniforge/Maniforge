<?php

require dirname(__DIR__) . '/src/bootstrap.php';

$workPlaceId = isset($_POST['iwp']) ? (int) $_POST['iwp'] : 6;
$workInputId = isset($_POST['iwi']) ? (int) $_POST['iwi'] : 501;

$form = task_service()->getCalculatorForm($workPlaceId, $workInputId);
if ($form === null) {
    json_response(['error' => 'Участок не найден'], 404);
}

ob_start();
view('partials/calculator-pickers', ['form' => $form]);
$fieldsHtml = ob_get_clean();

ob_start();
view('partials/picker-offcanvas');
$offcanvasHtml = ob_get_clean();

json_response([
    'html' => $fieldsHtml . $offcanvasHtml,
    'form' => $form,
]);
