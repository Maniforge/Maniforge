<?php
/** @var array $mock */
$mock = require __DIR__ . '/mock_data.php';

$all_global_dostup_array = ['all', 'print-masters'];
$global_dostup_array = ['all', 'print-masters', 'print-worker'];

// Заглушка авторизации: cookie не требуются
$user_id = 1;
$user_global_dostup = 'print-masters';
$user_info = ['id' => $user_id];

$input_id_work_input = $_GET['iwi'] ?? null;
$input_id_work_place = $_GET['iwp'] ?? null;

$ui_snm_boost = array_keys($mock['snm_boost']);
$all_snm_boost = $mock['snm_boost'];
$ui_wp_snm_boost = [6, 7];

$ui_staff_group = [20, 21];

$all_work_place = $mock['work_place'];
$ui_user_wp = [6, 7];
$user_info['ui_user_wp'] = $ui_user_wp;
$user_info['ui_user_sg'] = $ui_staff_group;

if ($input_id_work_place !== null && !in_array((int) $input_id_work_place, $ui_user_wp, true)) {
    $input_id_work_place = null;
} elseif (count($ui_user_wp) === 1) {
    $input_id_work_place = $ui_user_wp[0];
}

if ($input_id_work_input !== null) {
    $found = false;
    foreach ($mock['work_input'] as $wi) {
        if ((string) $wi['id'] === (string) $input_id_work_input && $wi['table_input'] === 'snm_rs') {
            $id_snm_rs = $wi['nomer_input'];
            if (isset($mock['snm_rs'][$id_snm_rs]) && in_array($mock['snm_rs'][$id_snm_rs]['snm_in'], $ui_snm_boost, true)) {
                $found = true;
            }
            break;
        }
    }
    if (!$found) {
        $input_id_work_input = null;
    }
}

$display_cat = '';
$display_calc = 'style="display:none"';
$array_etap_name = [
    'prin' => ['name' => 'Печать', 'key' => 0],
    'reg'  => ['name' => 'Регистрация товара', 'key' => 1],
    'qr'   => ['name' => 'Идет раскрой', 'key' => 2],
];
$itg_key_etap_name = 'prin';
$d = '18';
$m = '06';
$y = '2026';
$time_array = ['zp' => 'ЗП-1001', 'time' => '10:30'];
$bl_pr = 48;
$bl_bg = '';
$bl_tx = '12/25 (48%)';
