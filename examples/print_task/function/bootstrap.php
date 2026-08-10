<?php
/**
 * Общие настройки подключения для print_task.
 * Скопируйте config.local.example.php → config.local.php и укажите реальные параметры.
 */
$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    die('print_task: создайте function/config.local.php из config.local.example.php');
}

return require $configFile;
