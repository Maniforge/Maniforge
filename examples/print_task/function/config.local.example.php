<?php
/**
 * Пример конфигурации БД для print_task.
 * Скопируйте в config.local.php и заполните значениями из архива print-studio.
 */
return [
    'all' => [
        'host' => 'localhost',
        'database' => 'u1688586_all',
        'user' => 'u1688586_default',
        'password' => 'CHANGE_ME',
    ],
    'db' => [
        'host' => 'localhost',
        'database' => 'u1688586_default',
        'user' => 'u1688586_default',
        'password' => 'CHANGE_ME',
    ],
    'qr' => [
        'host' => '77.235.221.57:39369',
        'database' => 'scan_server',
        'user' => 'user_sklad_fb',
        'password' => 'CHANGE_ME',
    ],
    'sklad' => [
        'host' => '77.235.221.57:39369',
        'database' => 'sklad_fb',
        'user' => 'user_sklad_fb',
        'password' => 'CHANGE_ME',
    ],
    'timezone' => 'UTC+7',
];
