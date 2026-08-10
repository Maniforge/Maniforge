<?php
declare(strict_types=1);

namespace App\Maniforge\TenantLicensing\Support;

final class RequestContext
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $server,
        public readonly array $input,
    ) {
    }

    public function actor(): string
    {
        return trim((string) ($this->server['HTTP_X_ACTOR'] ?? 'system'));
    }

    public function bearerToken(): string
    {
        $header = (string) ($this->server['HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return '';
    }
}
