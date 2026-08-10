<?php
/**
 * Тестовые данные для print_task (режим PRINT_TASK_STUB).
 */
return [
    'people' => [
        ['id' => 1, 'login' => 'test', 'global_dostup' => 'print-masters', 'name' => 'Иванов И.И.'],
        ['id' => 2, 'login' => 'test', 'global_dostup' => 'print-worker', 'name' => 'Петров П.П.'],
        ['id' => 3, 'name' => 'Сидоров С.С.'],
    ],
    'snm_boost' => [
        101 => [
            'id' => 101,
            'name' => 'Муслин 240',
            'material' => 5,
            'size' => 12,
            'list_wp' => '6, 7',
            'product_type' => 8,
        ],
        102 => [
            'id' => 102,
            'name' => 'Поплин 150',
            'material' => 3,
            'size' => 10,
            'list_wp' => '6',
            'product_type' => 8,
        ],
    ],
    'staff_group' => [
        20 => ['id' => 20, 'list' => '1, 3', 'cnt' => 1],
        21 => ['id' => 21, 'list' => '2', 'cnt' => 2],
    ],
    'work_place' => [
        6 => [
            'id' => 6,
            'name' => 'Печать',
            'work_key' => 'print',
            'list_idwo' => '18, 19',
            'list_idsg' => '20, 21',
        ],
        7 => [
            'id' => 7,
            'name' => 'Сушка',
            'work_key' => 'white',
            'list_idwo' => '20',
            'list_idsg' => '21',
        ],
    ],
    'work_operations' => [
        18 => ['id' => 18, 'name' => 'Печать рулона'],
        19 => ['id' => 19, 'name' => 'Повторная печать'],
        20 => ['id' => 20, 'name' => 'Сушка'],
    ],
    'work_global' => [
        1001 => ['nomer' => 1001, 'name' => 'Заказ Озон #4521', 'createdAt' => '2026-06-15 09:00:00'],
        1002 => ['nomer' => 1002, 'name' => 'Заказ WB #8832', 'createdAt' => '2026-06-16 14:30:00'],
    ],
    'work_input' => [
        501 => ['id' => 501, 'nomer_work_global' => 1001, 'nomer_input' => 201, 'table_input' => 'snm_rs', 'count' => 25],
        502 => ['id' => 502, 'nomer_work_global' => 1001, 'nomer_input' => 202, 'table_input' => 'snm_rs', 'count' => 12],
        503 => ['id' => 503, 'nomer_work_global' => 1002, 'nomer_input' => 201, 'table_input' => 'snm_rs', 'count' => 8],
        601 => ['id' => 601, 'nomer_work_global' => 1001, 'nomer_input' => 301, 'table_input' => 'j_et', 'count' => 1],
    ],
    'snm_rs' => [
        201 => [
            'id' => 201,
            'snm_in' => 101,
            'srs_in' => 2,
            'id_colors' => 301,
            'rs_config' => 1,
            'snm_ext' => '10449',
            'srs_ext' => '1',
            'img' => '',
        ],
        202 => [
            'id' => 202,
            'snm_in' => 102,
            'srs_in' => 3,
            'id_colors' => 302,
            'rs_config' => 2,
            'snm_ext' => '10450',
            'srs_ext' => '2',
            'img' => '',
        ],
    ],
    'rs' => [
        2 => ['id' => 2, 'name' => '2 сп'],
        3 => ['id' => 3, 'name' => '1.5 сп'],
    ],
    'colors' => [
        301 => ['id' => 301, 'id_complect' => 662, 'main_color' => 7, 'cloth_c' => 'Б2, Б9'],
        302 => ['id' => 302, 'id_complect' => 663, 'main_color' => 3, 'cloth_c' => 'Б5'],
    ],
    'base_colors' => [
        7 => ['id' => 7, 'name' => 'Голубой'],
        3 => ['id' => 3, 'name' => 'Серый'],
    ],
    'complects' => [
        662 => ['id' => 662, 'name' => 'КПБ Кактусы', 'design_input' => '45 / 1, 46 / 2'],
        663 => ['id' => 663, 'name' => 'КПБ Листья', 'design_input' => '47 / 2'],
    ],
    'design' => [
        45 => ['id' => 45, 'name' => 'Кактусы'],
        46 => ['id' => 46, 'name' => 'Кактусы компаньон'],
        47 => ['id' => 47, 'name' => 'Листья'],
    ],
    'materials' => [
        5 => ['id' => 5, 'unit' => '7Б'],
        3 => ['id' => 3, 'unit' => '4Б'],
    ],
    'size' => [
        12 => ['idsize' => 12, 'unit' => '150'],
        10 => ['idsize' => 10, 'unit' => '120'],
    ],
    'j_et' => [
        301 => ['nomer_j_et' => 301, 'comment' => 'Этап печати', 'etap' => 'print', 'status' => 'work'],
    ],
    't_et' => [
        ['nomer_j_et' => 301, 'nomer_t_et' => 1, 'nomer_s_nm' => '10449', 'nomer_s_rs' => '1', 'id_t_et' => 9001, 'kol_vo' => 25],
    ],
    's_nm' => [
        '10449' => ['nomer_s_nm' => '10449', 'ean13_cz' => 4620046299617],
        '10450' => ['nomer_s_nm' => '10450', 'ean13_cz' => 0],
    ],
    'QRCode' => [
        ['reserveLinkId' => 9001, 'externalId' => 'ext-001'],
    ],
    'Item' => [
        'ext-001' => ['id' => 8001, 'externalId' => 'ext-001'],
    ],
    'Transition' => [
        8001 => ['itemId' => 8001, 'dataId' => 1, 'dataDeviceId' => 2, 'name' => 'stage38'],
    ],
];
