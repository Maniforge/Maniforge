<?php

require __DIR__ . '/src/bootstrap.php';

$svc = task_service();
$ctx = $svc->getContext($_GET['iwi'] ?? null, $_GET['iwp'] ?? null);
$tasks = $svc->getTaskCards();

$detail = null;
$calculatorForm = null;

if ($ctx['work_input_id'] !== null) {
    $detail = $svc->getTaskDetail($ctx['work_input_id']);
    if ($ctx['show_calculator'] && $ctx['work_place_id'] !== null) {
        $calculatorForm = $svc->getCalculatorForm(
            $ctx['work_place_id'],
            $ctx['work_input_id'],
            isset($_GET['io']) ? (int) $_GET['io'] : null,
            isset($_GET['isg']) ? (int) $_GET['isg'] : null,
        );
    }
}

$pageTitle = 'Задания — ' . app_config('name');
$contentTemplate = 'pages/tasks';
$pageData = compact('ctx', 'tasks', 'detail', 'calculatorForm');

$bodyClass = '';
if ($detail !== null) {
    $bodyClass .= ' app-shell--detail';
}
if ($detail !== null && $ctx['show_calculator'] && $ctx['work_place_id'] !== null) {
    $bodyClass .= ' app-shell--calc';
}

require APP_BASE . '/templates/layout.php';
