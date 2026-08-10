<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\ActionTokenRepository;

final class ActionTokenService
{
    public const PURPOSE_ADMIN_SENSITIVE = 'admin_sensitive';

    public function __construct(
        private readonly ActionTokenRepository $tokens = new ActionTokenRepository(),
    ) {
    }

    /**
     * @return array{action_token: string, expires_in: int, purpose: string}
     */
    public function issueForSession(array $session, string $purpose = self::PURPOSE_ADMIN_SENSITIVE): array
    {
        $sessionId = (string) $session['id'];
        $this->tokens->revokeActiveForSession($sessionId);

        $raw = bin2hex(random_bytes(24));
        $ttlSec = max(60, (int) ($_ENV['RBAC_ACTION_TOKEN_TTL_SEC'] ?? 900));

        $this->tokens->create([
            ':id' => bin2hex(random_bytes(16)),
            ':session_id' => $sessionId,
            ':user_id' => (int) $session['user_id'],
            ':tenant_id' => (string) $session['tenant_id'],
            ':subtenant_id' => (string) $session['subtenant_id'],
            ':token_hash' => hash('sha256', $raw),
            ':purpose' => $purpose,
            ':expires_at' => gmdate('Y-m-d H:i:s', time() + $ttlSec),
            ':created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return [
            'action_token' => $raw,
            'expires_in' => $ttlSec,
            'purpose' => $purpose,
        ];
    }

    public function authenticate(string $token, array $session): ?array
    {
        if ($token === '') {
            return null;
        }

        $row = $this->tokens->findActiveByToken($token);
        if ($row === null) {
            return null;
        }

        if ((string) $row['session_id'] !== (string) $session['id']) {
            return null;
        }

        if ((int) $row['user_id'] !== (int) $session['user_id']) {
            return null;
        }

        if (
            (string) $row['tenant_id'] !== (string) $session['tenant_id']
            || (string) $row['subtenant_id'] !== (string) $session['subtenant_id']
        ) {
            return null;
        }

        return $row;
    }

    public function revokeForSession(string $sessionId): int
    {
        return $this->tokens->revokeActiveForSession($sessionId);
    }

    public function revokeAllForUser(int $userId): int
    {
        return $this->tokens->revokeAllForUser($userId);
    }
}
