<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\RoleRepository;
use App\Maniforge\Rbac\Repository\SecurityEventRepository;

final class RoleAdminService
{
    private const PRIVILEGED_ROLES = ['super_admin', 'tenant_admin', 'subtenant_admin', 'security_auditor'];
    private const REQUIRED_SCOPE_ADMIN_ROLES = ['tenant_admin', 'subtenant_admin'];
    private const ROLE_LEVELS = [
        'super_admin' => 100,
        'tenant_admin' => 80,
        'subtenant_admin' => 60,
        'security_auditor' => 50,
        'support_operator' => 30,
        'moderator' => 20,
        'user' => 10,
    ];

    public function __construct(
        private readonly RoleRepository $roles = new RoleRepository(),
        private readonly SecurityEventRepository $securityEvents = new SecurityEventRepository(),
    ) {
    }

    public function guardRoleMutation(
        int $actorUserId,
        int $targetUserId,
        string $roleCode,
        string $operation,
        string $tenantId,
        string $subtenantId
    ): array {
        $isPrivileged = in_array($roleCode, self::PRIVILEGED_ROLES, true);
        $hierarchyGuard = $this->guardRoleHierarchy(
            $actorUserId,
            $targetUserId,
            $roleCode,
            $operation,
            $tenantId,
            $subtenantId
        );
        if (!$hierarchyGuard['ok']) {
            return $hierarchyGuard;
        }

        if ($operation === 'assign' && $targetUserId === $actorUserId && $isPrivileged) {
            $alreadyHas = $this->roles->hasRoleInScope($targetUserId, $tenantId, $subtenantId, $roleCode);
            if (!$alreadyHas) {
                $this->securityEvents->write(
                    'admin.user_role.assign.blocked_self_escalation',
                    $actorUserId,
                    $tenantId,
                    $subtenantId,
                    'warning',
                    ['role_code' => $roleCode]
                );
                return ['ok' => false, 'error' => 'Self-escalation запрещен'];
            }
        }

        if ($operation === 'revoke' && $targetUserId === $actorUserId && $isPrivileged) {
            $this->securityEvents->write(
                'admin.user_role.revoke.blocked_self_demotion',
                $actorUserId,
                $tenantId,
                $subtenantId,
                'warning',
                ['role_code' => $roleCode]
            );
            return ['ok' => false, 'error' => 'Self-demotion запрещен для privileged ролей'];
        }

        if ($operation === 'revoke' && in_array($roleCode, self::REQUIRED_SCOPE_ADMIN_ROLES, true)) {
            $targetHasRole = $this->roles->hasRoleInScope($targetUserId, $tenantId, $subtenantId, $roleCode);
            if ($targetHasRole) {
                $count = $this->roles->countUsersWithRoleInScope($tenantId, $subtenantId, $roleCode);
                if ($count <= 1) {
                    $this->securityEvents->write(
                        'admin.user_role.revoke.blocked_last_scope_admin',
                        $actorUserId,
                        $tenantId,
                        $subtenantId,
                        'warning',
                        ['role_code' => $roleCode, 'target_user_id' => $targetUserId]
                    );
                    return ['ok' => false, 'error' => "Нельзя снять последнюю роль {$roleCode} в контуре"];
                }
            }
        }

        return ['ok' => true];
    }

    private function guardRoleHierarchy(
        int $actorUserId,
        int $targetUserId,
        string $roleCode,
        string $operation,
        string $tenantId,
        string $subtenantId
    ): array {
        $actorLevel = $this->actorMaxRoleLevel($actorUserId, $tenantId, $subtenantId);
        $targetLevel = self::ROLE_LEVELS[$roleCode] ?? 0;

        if ($actorLevel >= self::ROLE_LEVELS['super_admin']) {
            return ['ok' => true];
        }

        if ($operation === 'assign' && $targetLevel >= $actorLevel) {
            $this->securityEvents->write(
                'admin.user_role.assign.blocked_hierarchy',
                $actorUserId,
                $tenantId,
                $subtenantId,
                'warning',
                ['role_code' => $roleCode, 'target_user_id' => $targetUserId]
            );
            return ['ok' => false, 'error' => 'Нельзя назначить роль уровня актера или выше'];
        }

        if ($operation === 'revoke' && $targetLevel > $actorLevel) {
            $this->securityEvents->write(
                'admin.user_role.revoke.blocked_hierarchy',
                $actorUserId,
                $tenantId,
                $subtenantId,
                'warning',
                ['role_code' => $roleCode, 'target_user_id' => $targetUserId]
            );
            return ['ok' => false, 'error' => 'Нельзя снять роль выше уровня актера'];
        }

        return ['ok' => true];
    }

    private function actorMaxRoleLevel(int $actorUserId, string $tenantId, string $subtenantId): int
    {
        $max = 0;
        foreach ($this->roles->userRoleCodes($actorUserId, $tenantId, $subtenantId) as $roleCode) {
            $max = max($max, self::ROLE_LEVELS[$roleCode] ?? 0);
        }

        return $max;
    }

    public function simulateBatchSummary(string $tenantId, string $subtenantId, array $items): array
    {
        $assigned = 0;
        $revoked = 0;
        $skipped = 0;

        foreach ($items as $item) {
            $userId = (int) ($item['user_id'] ?? 0);
            $roleCode = trim((string) ($item['role_code'] ?? ''));
            $action = trim((string) ($item['action'] ?? ''));

            $hasRole = $this->roles->hasRoleInScope($userId, $tenantId, $subtenantId, $roleCode);
            if ($action === 'assign') {
                if ($hasRole) {
                    $skipped++;
                } else {
                    $assigned++;
                }
                continue;
            }

            if ($action === 'revoke') {
                if ($hasRole) {
                    $revoked++;
                } else {
                    $skipped++;
                }
                continue;
            }

            $skipped++;
        }

        return [
            'assigned' => $assigned,
            'revoked' => $revoked,
            'skipped' => $skipped,
            'total' => count($items),
        ];
    }
}
