<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

final class PiiAuditSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'phone',
        'email',
        'password',
        'password_hash',
        'token',
        'refresh_token',
        'access_token',
        'session_secret_hash',
        'new_password',
        'current_password',
    ];

    public function scrubPayload(array $payload): array
    {
        $copy = [];
        foreach ($payload as $key => $value) {
            $copy[$key] = $this->scrubValue((string) $key, $value);
        }

        return $copy;
    }

    private function scrubValue(string $key, mixed $value): mixed
    {
        if (in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[redacted]';
        }

        if (is_array($value)) {
            $nested = [];
            foreach ($value as $k => $v) {
                $nested[$k] = $this->scrubValue((string) $k, $v);
            }

            return $nested;
        }

        return $value;
    }
}
