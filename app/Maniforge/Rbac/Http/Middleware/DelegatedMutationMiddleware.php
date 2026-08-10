<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http\Middleware;

use App\Maniforge\Rbac\Http\Router;
use App\Maniforge\Rbac\Security\DelegatedAccessPolicy;
use App\Maniforge\Rbac\Security\SessionService;

final class DelegatedMutationMiddleware
{
    public function validate(string $method, string $rawPath, array $server): array
    {
        if (!in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => true];
        }

        $header = (string) ($server['HTTP_AUTHORIZATION'] ?? '');
        if (!str_starts_with($header, 'Bearer ')) {
            return ['ok' => true];
        }

        $token = trim(substr($header, 7));
        if ($token === '') {
            return ['ok' => true];
        }

        $session = (new SessionService())->authenticate($token);
        if ($session === null) {
            return ['ok' => true];
        }

        $normalizedPath = (new Router())->normalizePathForMiddleware($rawPath);

        return (new DelegatedAccessPolicy())->allowsHttpMutation($session, $method, $normalizedPath);
    }
}
