<?php

$config = require dirname(__DIR__) . '/config/app.php';

define('APP_BASE', $config['base_path']);
define('APP_STUB', (bool) $config['stub']);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/TaskRepository.php';
require_once __DIR__ . '/TaskService.php';
require_once __DIR__ . '/OrderService.php';

if (APP_STUB) {
    require_once dirname(__DIR__) . '/function/stub.php';
}

function app_config(string $key, mixed $default = null): mixed
{
    static $cfg;
    $cfg ??= require dirname(__DIR__) . '/config/app.php';
    return $cfg[$key] ?? $default;
}

function task_service(): TaskService
{
    static $svc;
    $svc ??= new TaskService(new TaskRepository());
    return $svc;
}

function order_service(): OrderService
{
    static $svc;
    $svc ??= new OrderService(new TaskRepository());
    return $svc;
}
