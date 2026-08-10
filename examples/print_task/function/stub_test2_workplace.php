<?php
/** @var array $mock */
if (!isset($mock)) {
    $mock = require __DIR__ . '/mock_data.php';
}

$wp = $mock['work_place'][$input_id_work_place];
$list_idsg = $wp['list_idsg'];
$list_idwo = $wp['list_idwo'];
$name_work_place = $wp['name'];

$id_wo_list = array_map('trim', explode(',', $list_idwo));
$id_work_operations = (int) $id_wo_list[0];
$name_work_operations = $mock['work_operations'][$id_work_operations]['name'];

$id_sg_list = array_map('intval', array_map('trim', explode(',', $list_idsg)));
$id_staff_group = $id_sg_list[0];
$list_people = $mock['staff_group'][$id_staff_group]['list'];

$all_people = [];
$ui_people = array_map('trim', explode(', ', $list_people));
$unames_people = [];
foreach ($ui_people as $id_people) {
    foreach ($mock['people'] as $p) {
        if ((string) $p['id'] === (string) $id_people) {
            $all_people[$id_people]['name'] = $p['name'];
            $unames_people[] = $p['name'];
            break;
        }
    }
}
$name_staff_group = implode(', ', $unames_people);

$array_menuIn = [
    'where' => [
        'id' => $input_id_work_place,
        'name' => $name_work_place,
        'caption' => 'Выберите участок',
    ],
    'what' => [
        'id' => $id_work_operations,
        'name' => $name_work_operations,
        'caption' => 'Выберите операцию',
    ],
    'who' => [
        'id' => $id_staff_group,
        'name' => $name_staff_group,
        'caption' => 'Выберите исполнителей',
    ],
];
