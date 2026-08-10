<?php
declare(strict_types=1);

$root = dirname(__DIR__);

if (is_file($root . '/.env')) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = trim($value, " \t\"'");
    }
}

// Process env (CI / docker) wins over empty .env placeholders; fills missing keys.
foreach ([
    'APP_ENV', 'APP_DEBUG', 'APP_URL', 'APP_TIMEZONE',
    'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET',
    'TENANCY_MODE', 'DEFAULT_TENANT_ID', 'DEFAULT_SUBTENANT_ID',
] as $envKey) {
    $fromProcess = getenv($envKey);
    if ($fromProcess === false) {
        continue;
    }
    $current = $_ENV[$envKey] ?? null;
    if ($current === null || $current === '') {
        $_ENV[$envKey] = $fromProcess;
    }
}

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Moscow');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $root . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});
