<?php
/** @var array $mock */
if (!isset($mock)) {
    $mock = require __DIR__ . '/mock_data.php';
}

$ui_user_snm_boost = array_keys($mock['snm_boost']);
$ui_user_snm_boost_string = implode(', ', $ui_user_snm_boost);

$ui_snm_rs = array_keys($mock['snm_rs']);
$ui_snm_rs_string = implode(', ', $ui_snm_rs);

$un_work_global = array_keys($mock['work_global']);
$un_work_global_string = implode(', ', $un_work_global);

$all_work_input = [];
$ui_work_input = [];
foreach ($mock['work_input'] as $wi) {
    if ($wi['table_input'] !== 'snm_rs' || !in_array($wi['nomer_work_global'], $un_work_global, true)) {
        continue;
    }
    if (!in_array($wi['nomer_input'], $ui_snm_rs, true)) {
        continue;
    }
    $id = $wi['id'];
    $ui_work_input[] = $id;
    $all_work_input[$id] = [
        'id_snm_rs' => $wi['nomer_input'],
        'count' => $wi['count'],
        'nomer_work_global' => $wi['nomer_work_global'],
    ];
}

$all_snm_rs = [];
$ui_rs = [];
$ui_colors = [];
foreach ($mock['snm_rs'] as $id_snm_rs => $row) {
    if (!in_array($id_snm_rs, $ui_snm_rs, true)) {
        continue;
    }
    $all_snm_rs[$id_snm_rs] = [
        'rs_config' => $row['rs_config'],
        'id_snm_boost' => $row['snm_in'],
        'id_rs' => $row['srs_in'],
        'id_colors' => $row['id_colors'],
    ];
    $ui_rs[] = $row['srs_in'];
    $ui_colors[] = $row['id_colors'];
}
$ui_rs = array_unique($ui_rs);
$ui_colors = array_unique($ui_colors);

$all_name_rs = [];
foreach ($ui_rs as $id_rs) {
    $all_name_rs[$id_rs] = $mock['rs'][$id_rs]['name'];
}

$all_colors = [];
$ui_complects = [];
$ui_base_colors = [];
foreach ($ui_colors as $id_colors) {
    $c = $mock['colors'][$id_colors];
    $all_colors[$id_colors] = [
        'id_complect' => $c['id_complect'],
        'main_color' => $c['main_color'],
    ];
    $ui_complects[] = $c['id_complect'];
    $ui_base_colors[] = $c['main_color'];
}
$ui_complects = array_unique($ui_complects);
$ui_base_colors = array_unique($ui_base_colors);

$all_name_base_colors = [];
foreach ($ui_base_colors as $id) {
    $all_name_base_colors[$id] = $mock['base_colors'][$id]['name'];
}

$all_name_complects = [];
foreach ($ui_complects as $id) {
    $all_name_complects[$id] = $mock['complects'][$id]['name'];
}
