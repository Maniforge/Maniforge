<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http\Middleware;

final class CsrfMiddleware
{
    public function validate(string $method, string $path, array $server, array $input): array
    {
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => true];
        }

        // Login/refresh and internal webhooks are called by non-browser clients.
        if (in_array($path, [
            '/api/v1/auth/login',
            '/api/v1/auth/register',
            '/api/v1/auth/refresh',
            '/internal/v1/tenant-events',
        ], true)) {
            return ['ok' => true];
        }

        $token = (string) ($server['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '');
        $sessionToken = (string) ($_SESSION['csrf_token'] ?? '');
        if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
            return [
                'ok' => false,
                'status' => 403,
                'error' => 'Неверный CSRF-токен',
            ];
        }

        return ['ok' => true];
    }
}
