<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Http;

use App\Maniforge\Rbac\Http\Middleware\DelegatedMutationMiddleware;
use App\Maniforge\Rbac\Http\Middleware\RateLimitMiddleware;
use App\Maniforge\Rbac\Http\Middleware\SecurityHeadersMiddleware;
use App\Maniforge\Rbac\Http\Middleware\CsrfMiddleware;
use App\Maniforge\Rbac\Security\TenantResolver;
use App\Maniforge\Rbac\Support\JsonResponse;
use App\Maniforge\Rbac\Support\RequestContext;

final class Kernel
{
    public function handle(string $method, string $path, array $server, string $rawInput): void
    {
        $normalizedPath = (new Router())->normalizePathForMiddleware($path);
        (new SecurityHeadersMiddleware())->handle($normalizedPath);

        $rateLimit = new RateLimitMiddleware();
        if (!$rateLimit->allow($server)) {
            JsonResponse::send(['ok' => false, 'error' => 'Слишком много запросов'], 429);
            return;
        }

        $input = json_decode($rawInput, true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $tenant = (new TenantResolver())->resolve($server, $normalizedPath, $input);
        if (!$tenant['ok']) {
            JsonResponse::send($tenant, (int) $tenant['status']);
            return;
        }

        $csrf = (new CsrfMiddleware())->validate(strtoupper($method), $normalizedPath, $server, $input);
        if (!$csrf['ok']) {
            JsonResponse::send($csrf, (int) $csrf['status']);
            return;
        }

        $delegation = (new DelegatedMutationMiddleware())->validate($method, $path, $server);
        if (!$delegation['ok']) {
            JsonResponse::send($delegation, 403);
            return;
        }

        $ctx = new RequestContext(
            strtoupper($method),
            $path,
            $server,
            $input,
            $tenant
        );

        (new Router())->dispatch($ctx);
    }
}
