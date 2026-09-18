<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http\Middleware;

use App\Maniforge\Rbac\Repository\RateLimitRepository;

final class RateLimitMiddleware
{
    public function __construct(
        private readonly RateLimitRepository $rateLimits = new RateLimitRepository(),
    ) {
    }

    public function allow(array $server): bool
    {
        $ip = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $method = (string) ($server['REQUEST_METHOD'] ?? 'GET');
        $path = (string) ($server['REQUEST_URI'] ?? '/');
        $windowSec = (int) ($_ENV['RBAC_RATE_LIMIT_WINDOW_SEC'] ?? 60);
        $maxRequests = (int) ($_ENV['RBAC_RATE_LIMIT_MAX'] ?? 120);
        if (str_contains($path, '/api/v1/auth/login')) {
            $maxRequests = (int) ($_ENV['RBAC_RATE_LIMIT_LOGIN_MAX'] ?? 20);
        } elseif (str_contains($path, '/api/v1/auth/register')) {
            $maxRequests = (int) ($_ENV['RBAC_RATE_LIMIT_REGISTER_MAX'] ?? 10);
        } elseif (str_contains($path, '/api/v1/admin/')) {
            $maxRequests = (int) ($_ENV['RBAC_RATE_LIMIT_ADMIN_MAX'] ?? 60);
        }

        $bucketKey = hash('sha256', implode('|', [$ip, strtoupper($method), strtok($path, '?') ?: '/']));
        $count = $this->rateLimits->increment($bucketKey, $windowSec);

        return $count <= $maxRequests;
    }
}
