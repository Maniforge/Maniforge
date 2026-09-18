<?php
declare(strict_types=1);

/**
 * Local preview of the PHP /api docs UI (GitHub 80dac22).
 * php -S 127.0.0.1:8765 -t public tools/api-docs-preview-router.php
 */

namespace App\Maniforge\Rbac\Security {
    final class RegistrationService
    {
        public function isEnabled(): bool
        {
            return false;
        }
    }
}

namespace {
    $root = dirname(__DIR__);
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $uri = rtrim($uri, '/') ?: '/';

    $publicFile = $root . '/public' . ($uri === '/' ? '/index.php' : $uri);
    if ($uri !== '/api' && is_file($publicFile) && !str_ends_with($publicFile, '.php')) {
        return false;
    }

    if ($uri === '/' || $uri === '/api') {
        $_ENV['APP_ENV'] = 'local';
        $_SERVER['REQUEST_URI'] = '/api';
        require $root . '/templates/api.php';
        return true;
    }

    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Preview routes: /api\n";
    return true;
}
