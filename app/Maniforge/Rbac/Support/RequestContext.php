<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Support;

final class RequestContext
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $server,
        public readonly array $input,
        public readonly array $tenant,
    ) {
    }

    public function bearerToken(): string
    {
        $header = (string) ($this->server['HTTP_AUTHORIZATION'] ?? '');
        if (str_starts_with($header, 'Bearer ')) {
            return trim(substr($header, 7));
        }

        return '';
    }

    /** Короткоживущий ключ для чувствительных операций после reauth. */
    public function actionToken(): string
    {
        return trim((string) ($this->server['HTTP_X_ACTION_TOKEN'] ?? ''));
    }
}
