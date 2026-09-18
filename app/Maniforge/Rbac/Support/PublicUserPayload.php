<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Support;

/** Публичный контур auth: без внутреннего login. */
final class PublicUserPayload
{
    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public static function fromUser(array $user): array
    {
        $payload = [
            'id' => (int) ($user['id'] ?? 0),
            'phone' => (string) ($user['phone'] ?? ''),
            'status' => (string) ($user['status'] ?? 'active'),
        ];
        $email = (string) ($user['email'] ?? '');
        if ($email !== '') {
            $payload['email'] = $email;
        }

        return $payload;
    }
}
