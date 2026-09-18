<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\PasswordHistoryRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;
use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Versioning\Security\ChangeRecorder;

final class UserSecurityService
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly PasswordService $passwords = new PasswordService(),
        private readonly PasswordHistoryRepository $history = new PasswordHistoryRepository(),
        private readonly SessionService $sessions = new SessionService(),
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
        private readonly ChangeRecorder $versioning = new ChangeRecorder(),
    ) {
    }

    public function changePassword(array $session, string $currentPassword, string $newPassword): array
    {
        $userId = (int) $session['user_id'];
        $user = $this->users->findByIdForSession($session);
        if ($user === null) {
            return ['ok' => false, 'status' => 404, 'error' => 'Пользователь не найден'];
        }

        if (!$this->passwords->verify($currentPassword, (string) $user['password_hash'])) {
            return ['ok' => false, 'status' => 403, 'error' => 'Текущий пароль неверный'];
        }

        $minLength = (int) ($_ENV['RBAC_PASSWORD_MIN_LENGTH'] ?? 12);
        if (mb_strlen($newPassword) < $minLength) {
            return ['ok' => false, 'status' => 422, 'error' => "Новый пароль должен быть не короче {$minLength} символов"];
        }

        if ($currentPassword === $newPassword) {
            return ['ok' => false, 'status' => 422, 'error' => 'Новый пароль должен отличаться от текущего'];
        }

        $historyLimit = (int) ($_ENV['RBAC_PASSWORD_HISTORY_CHECK'] ?? 5);
        $recentHashes = $this->history->recentHashes($userId, $historyLimit);
        foreach ($recentHashes as $hash) {
            if ($this->passwords->verify($newPassword, $hash)) {
                return ['ok' => false, 'status' => 422, 'error' => 'Нельзя использовать один из последних паролей'];
            }
        }
        if ($this->passwords->verify($newPassword, (string) $user['password_hash'])) {
            return ['ok' => false, 'status' => 422, 'error' => 'Новый пароль совпадает с текущим'];
        }

        $newHash = $this->passwords->hash($newPassword);
        $pdo = Connection::get();

        try {
            $pdo->beginTransaction();
            $this->history->add($userId, (string) $user['password_hash']);
            $update = $pdo->prepare(
                'UPDATE maniforge_users
                 SET password_hash = :password_hash,
                     security_version = security_version + 1,
                     last_password_changed_at = UTC_TIMESTAMP(),
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id'
            );
            $update->execute([
                ':password_hash' => $newHash,
                ':id' => $userId,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return ['ok' => false, 'status' => 500, 'error' => 'Ошибка обновления пароля'];
        }

        $revoked = $this->sessions->revokeAllForUser($userId, 'password_changed');
        $tenantId = (string) $session['tenant_id'];
        $subtenantId = (string) $session['subtenant_id'];
        $after = $this->users->findByIdForSession($session);
        if ($after !== null) {
            $this->versioning->record(
                [
                    'tenant_id' => $tenantId,
                    'subtenant_id' => $subtenantId,
                    'project_id' => null,
                    'actor_user_id' => $userId,
                    'correlation_id' => null,
                ],
                'maniforge_users',
                (string) $userId,
                'update',
                $user,
                $after,
                (string) ($after['login'] ?? $userId)
            );
        }
        $this->audit->write('auth.password.changed', $userId, $tenantId, $subtenantId, [
            'revoked_sessions' => $revoked,
        ]);
        $this->securityEvents->write('auth.password.changed', $userId, $tenantId, $subtenantId, 'warning', [
            'revoked_sessions' => $revoked,
        ]);

        return [
            'ok' => true,
            'status' => 200,
            'revoked_sessions' => $revoked,
            'message' => 'Пароль обновлен. Все сессии завершены',
        ];
    }
}
