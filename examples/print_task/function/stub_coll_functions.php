<?php
/**
 * Заглушка coll_functions.php — переменные для info_show.php.
 *
 * @var array $un_work_global
 * @var string $un_work_global_string
 */
$mock = require __DIR__ . '/mock_data.php';

$un_j_et = [];
$ui_snm_rs = [];
$all_work_input = [];

foreach ($mock['work_input'] as $wi) {
    if (!in_array($wi['nomer_work_global'], $un_work_global, true)) {
        continue;
    }
    $nomer_work_global = $wi['nomer_work_global'];
    if ($wi['table_input'] === 'j_et' && !in_array($wi['nomer_input'], $un_j_et, true)) {
        $un_j_et[] = $wi['nomer_input'];
    } elseif ($wi['table_input'] === 'snm_rs' && !in_array($wi['nomer_input'], $ui_snm_rs, true)) {
        $ui_snm_rs[] = $wi['nomer_input'];
    }
    if (!isset($all_work_input[$nomer_work_global])) {
        $all_work_input[$nomer_work_global] = [$wi];
    } else {
        $all_work_input[$nomer_work_global][] = $wi;
    }
}

$all_j_et = [];
foreach ($un_j_et as $nomer_j_et) {
    if (isset($mock['j_et'][$nomer_j_et])) {
        $all_j_et[$nomer_j_et] = $mock['j_et'][$nomer_j_et];
    }
}

$ui_t_et = [];
$un_s_nm = [];
$un_ext = [];
$all_j_t_et = [];
foreach ($mock['t_et'] as $res_t_et) {
    if (!in_array($res_t_et['nomer_j_et'], $un_j_et, true)) {
        continue;
    }
    $nomer_j_et = $res_t_et['nomer_j_et'];
    $id_t_et = $res_t_et['id_t_et'];
    if (!in_array($id_t_et, $ui_t_et, true)) {
        $ui_t_et[] = $id_t_et;
    }
    $all_j_t_et[$nomer_j_et][$id_t_et] = $res_t_et;
    $nomer_s_nm = $res_t_et['nomer_s_nm'];
    if (!in_array($nomer_s_nm, $un_s_nm, true)) {
        $un_s_nm[] = $nomer_s_nm;
    }
    $nomer_ext = $nomer_s_nm . '/' . $res_t_et['nomer_s_rs'];
    if (!in_array($nomer_ext, $un_ext, true)) {
        $un_ext[] = $nomer_ext;
    }
}

$all_snm_rs = [];
$ui_snm_boost = [];
$ui_rs = [];
foreach ($ui_snm_rs as $id_snm_rs) {
    if (!isset($mock['snm_rs'][$id_snm_rs])) {
        continue;
    }
    $res = $mock['snm_rs'][$id_snm_rs];
    $all_snm_rs[$id_snm_rs] = $res;
    $ui_snm_boost[] = $res['snm_in'];
    $un_s_nm[] = $res['snm_ext'];
    $un_ext[] = $res['snm_ext'] . '/' . $res['srs_ext'];
    $ui_rs[] = $res['srs_in'];
}
$ui_snm_boost = array_unique($ui_snm_boost);
$ui_rs = array_unique($ui_rs);
$un_s_nm = array_unique($un_s_nm);

$all_rs = [];
foreach ($ui_rs as $id_rs) {
    $all_rs[$id_rs] = $mock['rs'][$id_rs];
}

$all_snm_boost = [];
foreach ($ui_snm_boost as $id) {
    $all_snm_boost[$id] = $mock['snm_boost'][$id];
}

$all_s_nm = [];
foreach ($un_s_nm as $nomer_s_nm) {
    if (isset($mock['s_nm'][$nomer_s_nm])) {
        $all_s_nm[$nomer_s_nm] = $mock['s_nm'][$nomer_s_nm];
    }
}

$all_QRCode_t_et = [];
$ui_external = [];
foreach ($mock['QRCode'] as $row) {
    $id_t_et = $row['reserveLinkId'];
    if (!in_array($id_t_et, $ui_t_et, true)) {
        continue;
    }
    $externalId = $row['externalId'];
    if (!in_array($externalId, $ui_external, true)) {
        $ui_external[] = $externalId;
    }
    if (!isset($all_QRCode_t_et[$id_t_et])) {
        $all_QRCode_t_et[$id_t_et] = [$externalId];
    } elseif (!in_array($externalId, $all_QRCode_t_et[$id_t_et], true)) {
        $all_QRCode_t_et[$id_t_et][] = $externalId;
    }
}

$all_Item_ext_QR = [];
$ui_item = [];
foreach ($mock['Item'] as $externalId => $res_Item) {
    if (!in_array($externalId, $ui_external, true)) {
        continue;
    }
    $all_Item_ext_QR[$externalId] = $res_Item;
    $ui_item[] = $res_Item['id'];
}
$ui_item = array_unique($ui_item);

$all_Transition_id_item = [];
$un_dataId_deviceId = [];
$ui_dataId = [];
$ui_dataDeviceId = [];
foreach ($mock['Transition'] as $id_Item => $res_Transition) {
    if (!in_array($id_Item, $ui_item, true)) {
        continue;
    }
    $all_Transition_id_item[$id_Item] = $res_Transition;
    $ui_dataId[] = $res_Transition['dataId'];
    $ui_dataDeviceId[] = $res_Transition['dataDeviceId'];
    $un_dataId_deviceId[] = $res_Transition['dataId'] . '/' . $res_Transition['dataDeviceId'];
}

$list_cnt = '';
