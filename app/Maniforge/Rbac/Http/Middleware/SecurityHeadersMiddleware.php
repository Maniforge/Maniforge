<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http\Middleware;

final class SecurityHeadersMiddleware
{
    public function handle(string $normalizedPath): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');

        $uiPaths = ['/', '/admin', '/api-docs'];
        if (in_array($normalizedPath, $uiPaths, true)) {
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self';");
            return;
        }

        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none';");
    }
}
