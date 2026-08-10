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

// Process env (CI / docker) fills missing or empty keys from .env.
foreach ($_SERVER as $envKey => $fromProcess) {
    if (!is_string($envKey) || !is_string($fromProcess)) {
        continue;
    }
    if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $envKey)) {
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
