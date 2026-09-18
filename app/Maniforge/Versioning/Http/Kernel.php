<?php
declare(strict_types=1);

namespace App\Maniforge\Versioning\Http;

use App\Maniforge\Rbac\Support\RequestContext;

final class Kernel
{
    public function handle(string $method, string $path, array $server, string $rawInput): void
    {
        $input = json_decode($rawInput, true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $ctx = new RequestContext(
            strtoupper($method),
            $path,
            $server,
            $input,
            [
                'ok' => true,
                'tenant_id' => '',
                'subtenant_id' => '',
            ]
        );

        (new Router())->dispatch($ctx);
    }
}
