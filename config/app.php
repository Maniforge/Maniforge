<?php
declare(strict_types=1);

return [
    'name' => $_ENV['APP_NAME'] ?? 'test-calculation',
    'env' => $_ENV['APP_ENV'] ?? 'local',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'http://127.0.0.1:8092',
];
