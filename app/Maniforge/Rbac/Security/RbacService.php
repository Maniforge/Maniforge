<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Maniforge\Rbac\Repository\RoleRepository;

final class RbacService
{
    public function __construct(
        private readonly RoleRepository $roles = new RoleRepository(),
    ) {
    }

    public function hasAnyRole(int $userId, string $tenantId, string $subtenantId, array $required): bool
    {
        $codes = $this->roles->userRoleCodes($userId, $tenantId, $subtenantId);
        if ($codes === []) {
            return false;
        }

        return count(array_intersect($required, $codes)) > 0;
    }

    public function hasPermission(int $userId, string $tenantId, string $subtenantId, string $permission): bool
    {
        $codes = $this->roles->userPermissionCodes($userId, $tenantId, $subtenantId);
        return in_array($permission, $codes, true);
    }

    public function permissionsForUser(int $userId, string $tenantId, string $subtenantId): array
    {
        return $this->roles->userPermissionCodes($userId, $tenantId, $subtenantId);
    }

    public function rolesForUser(int $userId, string $tenantId, string $subtenantId): array
    {
        return $this->roles->userRoleCodes($userId, $tenantId, $subtenantId);
    }

    public function effectiveAccess(int $userId, string $tenantId, string $subtenantId): array
    {
        return [
            'roles' => $this->rolesForUser($userId, $tenantId, $subtenantId),
            'permissions' => $this->permissionsForUser($userId, $tenantId, $subtenantId),
        ];
    }
}
