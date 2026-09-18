<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class RoleRepository
{
    public function userRoleCodes(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT r.code
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND ur.tenant_id = :tenant_id
               AND ur.subtenant_id = :subtenant_id
               AND (ur.expires_at IS NULL OR ur.expires_at > UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return array_map(static fn (array $row): string => (string) $row['code'], $stmt->fetchAll());
    }

    public function userPermissionCodes(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT DISTINCT p.code
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_role_permissions rp ON rp.role_id = ur.role_id
             INNER JOIN maniforge_permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id
               AND ur.tenant_id = :tenant_id
               AND ur.subtenant_id = :subtenant_id
               AND (ur.expires_at IS NULL OR ur.expires_at > UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return array_map(static fn (array $row): string => (string) $row['code'], $stmt->fetchAll());
    }

    public function listRoles(?string $scopePrefix = null): array
    {
        if ($scopePrefix === null || $scopePrefix === '') {
            $stmt = Connection::get()->query(
                'SELECT id, code, name, is_system, created_at
                 FROM maniforge_roles
                 ORDER BY code ASC'
            );

            return $stmt->fetchAll();
        }

        $stmt = Connection::get()->prepare(
            'SELECT id, code, name, is_system, created_at
             FROM maniforge_roles
             WHERE is_system = 1 OR code LIKE :scope_prefix
             ORDER BY is_system DESC, code ASC'
        );
        $stmt->execute([':scope_prefix' => $scopePrefix . '%']);

        return $stmt->fetchAll();
    }

    public function listPermissions(): array
    {
        $stmt = Connection::get()->query(
            'SELECT id, code, description
             FROM maniforge_permissions
             ORDER BY code ASC'
        );

        return $stmt->fetchAll();
    }

    public function listRolePermissions(string $roleCode): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT p.id, p.code, p.description
             FROM maniforge_role_permissions rp
             INNER JOIN maniforge_roles r ON r.id = rp.role_id
             INNER JOIN maniforge_permissions p ON p.id = rp.permission_id
             WHERE r.code = :role_code
             ORDER BY p.code ASC'
        );
        $stmt->execute([':role_code' => $roleCode]);

        return $stmt->fetchAll();
    }

    public function createRole(string $code, string $name): array
    {
        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_roles (code, name, is_system)
             VALUES (:code, :name, 0)'
        );
        $stmt->execute([':code' => $code, ':name' => $name]);

        return $this->findRoleByCode($code) ?? [];
    }

    public function updateRole(string $code, string $name): ?array
    {
        $stmt = Connection::get()->prepare(
            'UPDATE maniforge_roles
             SET name = :name
             WHERE code = :code AND is_system = 0'
        );
        $stmt->execute([':code' => $code, ':name' => $name]);

        return $this->findRoleByCode($code);
    }

    public function deleteRole(string $code): bool
    {
        $stmt = Connection::get()->prepare('DELETE FROM maniforge_roles WHERE code = :code AND is_system = 0');
        $stmt->execute([':code' => $code]);

        return $stmt->rowCount() > 0;
    }

    public function replaceRolePermissions(string $roleCode, array $permissionCodes): array
    {
        $pdo = Connection::get();
        $role = $this->findRoleByCode($roleCode);
        if ($role === null) {
            return ['ok' => false, 'error' => 'role_not_found'];
        }

        $permissionCodes = array_values(array_unique(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            $permissionCodes
        ))));
        $permissions = $this->findPermissionsByCodes($permissionCodes);
        if (count($permissions) !== count($permissionCodes)) {
            $found = array_map(static fn (array $row): string => (string) $row['code'], $permissions);

            return [
                'ok' => false,
                'error' => 'permission_not_found',
                'missing' => array_values(array_diff($permissionCodes, $found)),
            ];
        }

        try {
            $pdo->beginTransaction();
            $delete = $pdo->prepare('DELETE FROM maniforge_role_permissions WHERE role_id = :role_id');
            $delete->execute([':role_id' => (int) $role['id']]);

            $insert = $pdo->prepare(
                'INSERT INTO maniforge_role_permissions (role_id, permission_id)
                 VALUES (:role_id, :permission_id)'
            );
            foreach ($permissions as $permission) {
                $insert->execute([
                    ':role_id' => (int) $role['id'],
                    ':permission_id' => (int) $permission['id'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'ok' => true,
            'permissions' => $this->listRolePermissions($roleCode),
        ];
    }

    public function listUserRoles(int $userId, string $tenantId, string $subtenantId): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT ur.id, r.code AS role_code, r.name AS role_name, ur.assigned_by, ur.assigned_at, ur.expires_at
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND ur.tenant_id = :tenant_id
               AND ur.subtenant_id = :subtenant_id
             ORDER BY ur.id DESC'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return $stmt->fetchAll();
    }

    public function assignRoleByCode(
        int $userId,
        string $tenantId,
        string $subtenantId,
        string $roleCode,
        int $assignedBy
    ): bool {
        $role = $this->findRoleByCode($roleCode);
        if ($role === null) {
            return false;
        }

        $existing = Connection::get()->prepare(
            'SELECT id
             FROM maniforge_user_roles
             WHERE user_id = :user_id
               AND role_id = :role_id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
             LIMIT 1'
        );
        $existing->execute([
            ':user_id' => $userId,
            ':role_id' => (int) $role['id'],
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);
        if (is_array($existing->fetch())) {
            return true;
        }

        try {
            $insert = Connection::get()->prepare(
                'INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by, assigned_at)
                 VALUES (:user_id, :role_id, :tenant_id, :subtenant_id, :assigned_by, UTC_TIMESTAMP())'
            );
            $insert->execute([
                ':user_id' => $userId,
                ':role_id' => (int) $role['id'],
                ':tenant_id' => $tenantId,
                ':subtenant_id' => $subtenantId,
                ':assigned_by' => $assignedBy,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
        }

        return true;
    }

    public function revokeRoleByCode(int $userId, string $tenantId, string $subtenantId, string $roleCode): bool
    {
        $role = $this->findRoleByCode($roleCode);
        if ($role === null) {
            return false;
        }

        $delete = Connection::get()->prepare(
            'DELETE FROM maniforge_user_roles
             WHERE user_id = :user_id
               AND role_id = :role_id
               AND tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id'
        );
        $delete->execute([
            ':user_id' => $userId,
            ':role_id' => (int) $role['id'],
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
        ]);

        return $delete->rowCount() > 0;
    }

    public function applyRoleMutationsBatch(
        string $tenantId,
        string $subtenantId,
        int $assignedBy,
        array $operations
    ): array {
        $pdo = Connection::get();
        $assigned = 0;
        $revoked = 0;
        $skipped = 0;

        try {
            $pdo->beginTransaction();

            foreach ($operations as $operation) {
                $userId = (int) ($operation['user_id'] ?? 0);
                $roleCode = (string) ($operation['role_code'] ?? '');
                $action = (string) ($operation['action'] ?? '');

                $role = $this->findRoleByCode($roleCode);
                if ($role === null) {
                    throw new \RuntimeException("Role not found: {$roleCode}");
                }
                $roleId = (int) $role['id'];

                if ($action === 'assign') {
                    $exists = $pdo->prepare(
                        'SELECT id
                         FROM maniforge_user_roles
                         WHERE user_id = :user_id
                           AND role_id = :role_id
                           AND tenant_id = :tenant_id
                           AND subtenant_id = :subtenant_id
                           AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
                         LIMIT 1'
                    );
                    $exists->execute([
                        ':user_id' => $userId,
                        ':role_id' => $roleId,
                        ':tenant_id' => $tenantId,
                        ':subtenant_id' => $subtenantId,
                    ]);
                    if (is_array($exists->fetch())) {
                        $skipped++;
                        continue;
                    }

                    try {
                        $insert = $pdo->prepare(
                            'INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by, assigned_at)
                             VALUES (:user_id, :role_id, :tenant_id, :subtenant_id, :assigned_by, UTC_TIMESTAMP())'
                        );
                        $insert->execute([
                            ':user_id' => $userId,
                            ':role_id' => $roleId,
                            ':tenant_id' => $tenantId,
                            ':subtenant_id' => $subtenantId,
                            ':assigned_by' => $assignedBy,
                        ]);
                        $assigned++;
                    } catch (\PDOException $e) {
                        if ($e->getCode() !== '23000') {
                            throw $e;
                        }
                        $skipped++;
                    }
                    continue;
                }

                if ($action === 'revoke') {
                    $delete = $pdo->prepare(
                        'DELETE FROM maniforge_user_roles
                         WHERE user_id = :user_id
                           AND role_id = :role_id
                           AND tenant_id = :tenant_id
                           AND subtenant_id = :subtenant_id'
                    );
                    $delete->execute([
                        ':user_id' => $userId,
                        ':role_id' => $roleId,
                        ':tenant_id' => $tenantId,
                        ':subtenant_id' => $subtenantId,
                    ]);
                    if ($delete->rowCount() > 0) {
                        $revoked++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                throw new \RuntimeException("Unsupported action: {$action}");
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'assigned' => $assigned,
            'revoked' => $revoked,
            'skipped' => $skipped,
            'total' => count($operations),
        ];
    }

    public function hasRoleInScope(int $userId, string $tenantId, string $subtenantId, string $roleCode): bool
    {
        $stmt = Connection::get()->prepare(
            'SELECT ur.id
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
               AND ur.tenant_id = :tenant_id
               AND ur.subtenant_id = :subtenant_id
               AND r.code = :role_code
               AND (ur.expires_at IS NULL OR ur.expires_at > UTC_TIMESTAMP())
             LIMIT 1'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':role_code' => $roleCode,
        ]);

        return is_array($stmt->fetch());
    }

    public function countUsersWithRoleInScope(string $tenantId, string $subtenantId, string $roleCode): int
    {
        $stmt = Connection::get()->prepare(
            'SELECT COUNT(DISTINCT ur.user_id) AS total
             FROM maniforge_user_roles ur
             INNER JOIN maniforge_roles r ON r.id = ur.role_id
             WHERE ur.tenant_id = :tenant_id
               AND ur.subtenant_id = :subtenant_id
               AND r.code = :role_code
               AND (ur.expires_at IS NULL OR ur.expires_at > UTC_TIMESTAMP())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':role_code' => $roleCode,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['total'] ?? 0);
    }

    public function findRoleByCode(string $roleCode): ?array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, code, name, is_system, created_at FROM maniforge_roles WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $roleCode]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function findPermissionsByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($codes as $index => $code) {
            $key = ':code_' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $stmt = Connection::get()->prepare(
            'SELECT id, code
             FROM maniforge_permissions
             WHERE code IN (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        return $stmt->fetchAll();
    }
}
