<?php
/** @var array $mock */
if (!isset($mock)) {
    $mock = require __DIR__ . '/mock_data.php';
}

$wi = null;
foreach ($mock['work_input'] as $row) {
    if ((string) $row['id'] === (string) $input_id_work_input && $row['table_input'] === 'snm_rs') {
        $wi = $row;
        break;
    }
}

$id_snm_rs = $wi['nomer_input'];
$res_snm_rs = $mock['snm_rs'][$id_snm_rs];
$id_snm_boost = $res_snm_rs['snm_in'];
$rs_config = $res_snm_rs['rs_config'];
$id_colors = $res_snm_rs['id_colors'];
$img = $res_snm_rs['img'] ?? '';

$res_colors = $mock['colors'][$id_colors];
$id_complect = $res_colors['id_complect'];
$main_color = $res_colors['main_color'];
$cloth_c = $res_colors['cloth_c'];

$design_input = $mock['complects'][$id_complect]['design_input'];
$dia = explode(', ', $design_input);
$f_design = null;
$f_cloth_c = '';
foreach ($dia as $key_dia => $inp_dia) {
    [$id_design, $id_sost] = explode(' / ', $inp_dia);
    if ((int) $id_sost === (int) $rs_config) {
        $f_design = $id_design;
        $array_cloth_c = explode(', ', $cloth_c);
        $f_cloth_c = $array_cloth_c[$key_dia] ?? '';
        break;
    }
}

$name_design = $mock['design'][$f_design]['name'];
$nomer_s_nm = $res_snm_rs['snm_ext'];
$nomer_s_rs = $res_snm_rs['srs_ext'];
$id_materials = $all_snm_boost[$id_snm_boost]['material'];
$id_size = $all_snm_boost[$id_snm_boost]['size'];
$unit_materials = $mock['materials'][$id_materials]['unit'];
$unit_size = $mock['size'][$id_size]['unit'];

$art_cloth = $name_design . '_' . $f_design . '-' . $unit_size . '-' . $unit_materials . $f_cloth_c
    . ' (' . $nomer_s_nm . '/' . $nomer_s_rs . ')';
$cloth_img_path = '/complects/complects_img/' . $id_complect . '_' . $main_color . '_' . $rs_config . '.png';
$img_path = ($img !== null && $img !== '') ? '/collections/' . $img : $cloth_img_path;
