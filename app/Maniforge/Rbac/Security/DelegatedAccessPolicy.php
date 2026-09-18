<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

final class DelegatedAccessPolicy
{
    /** @var list<string> */
    private const READ_ONLY_MUTATION_PATHS = [
        '/api/v1/auth/logout',
        '/api/v1/auth/logout-all',
        '/api/v1/auth/refresh',
        '/api/v1/auth/reauth',
        '/api/v1/auth/switch-context',
        '/api/v1/auth/switch-project',
    ];

    /** Префиксы API, запрещённые для delegated grant_level=operator */
    private const OPERATOR_BLOCKED_PREFIXES = [
        '/api/v1/admin/users',
        '/api/v1/admin/user-roles',
        '/api/v1/admin/sessions/revoke',
        '/api/v1/admin/sessions/batch-revoke',
        '/api/v1/admin/registration-invites',
        '/api/v1/admin/policies',
        '/api/v1/admin/personal-data/operator-profile',
        '/api/v1/admin/personal-data/purposes',
        '/api/v1/admin/personal-data/subject-requests/resolve',
        '/api/v1/admin/roles',
        '/api/v1/admin/role-permissions',
    ];

    public function __construct(
        private readonly ContextService $contexts = new ContextService(),
    ) {
    }

    /**
     * @param array<string, mixed> $session
     */
    public function allowsHttpMutation(array $session, string $method, string $normalizedPath): array
    {
        $method = strtoupper(trim($method));
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return ['ok' => true];
        }

        $grantLevel = $this->resolveGrantLevel($session);
        if ($grantLevel === null) {
            return ['ok' => true];
        }

        $path = '/' . ltrim($normalizedPath, '/');
        if (in_array($path, self::READ_ONLY_MUTATION_PATHS, true)) {
            return ['ok' => true];
        }

        if ($grantLevel === 'read_only') {
            return [
                'ok' => false,
                'error' => 'Делегированный доступ read_only: изменение данных запрещено',
                'code' => 'delegated_read_only',
                'grant_level' => $grantLevel,
            ];
        }

        if ($grantLevel === 'operator' && $this->matchesOperatorBlockedPrefix($path)) {
            return [
                'ok' => false,
                'error' => 'Делегированный operator: эта операция доступна только с grant_level admin',
                'code' => 'delegated_operator_restricted',
                'grant_level' => $grantLevel,
            ];
        }

        return ['ok' => true];
    }

    private function matchesOperatorBlockedPrefix(string $path): bool
    {
        foreach (self::OPERATOR_BLOCKED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $session
     */
    public function resolveGrantLevel(array $session): ?string
    {
        $ctx = $this->contexts->contextsForSession($session);
        if (($ctx['ok'] ?? false) !== true) {
            return null;
        }

        $current = $ctx['current'] ?? null;
        if (!is_array($current)) {
            return null;
        }

        if (($current['delegated'] ?? false) !== true && ($current['kind'] ?? '') !== 'delegated') {
            return null;
        }

        $level = strtolower(trim((string) ($current['grant_level'] ?? '')));

        return $level !== '' ? $level : null;
    }
}
