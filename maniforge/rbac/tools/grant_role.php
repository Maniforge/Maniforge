<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\UserRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

[$script, $login, $roleCode, $tenantId, $subtenantId] = array_pad($argv, 5, null);

if (!$login || !$roleCode) {
    fwrite(STDERR, "Usage: php maniforge/rbac/tools/grant_role.php <login> <role_code> [tenant] [subtenant]\n");
    fwrite(STDERR, "Example: php maniforge/rbac/tools/grant_role.php admin super_admin default default\n");
    exit(1);
}

$tenantId = $tenantId ?: ($_ENV['DEFAULT_TENANT_ID'] ?? 'default');
$subtenantId = $subtenantId ?: ($_ENV['DEFAULT_SUBTENANT_ID'] ?? 'default');
$login = strtolower(trim((string) $login));
$roleCode = trim((string) $roleCode);

$users = new UserRepository();
$user = $users->findByLogin($tenantId, $subtenantId, $login);
if ($user === null) {
    fwrite(STDERR, "User not found: {$login} @ {$tenantId}/{$subtenantId}\n");
    exit(1);
}

$pdo = Connection::get();
$roleStmt = $pdo->prepare('SELECT id FROM maniforge_roles WHERE code = :code LIMIT 1');
$roleStmt->execute([':code' => $roleCode]);
$role = $roleStmt->fetch();
if (!is_array($role)) {
    fwrite(STDERR, "Role not found: {$roleCode}\n");
    exit(1);
}

$userId = (int) $user['id'];
$exists = $pdo->prepare(
    'SELECT id FROM maniforge_user_roles
     WHERE user_id = :user_id AND role_id = :role_id
       AND tenant_id = :tenant_id AND subtenant_id = :subtenant_id
       AND (expires_at IS NULL OR expires_at > UTC_TIMESTAMP())
     LIMIT 1'
);
$exists->execute([
    ':user_id' => $userId,
    ':role_id' => (int) $role['id'],
    ':tenant_id' => $tenantId,
    ':subtenant_id' => $subtenantId,
]);
if (is_array($exists->fetch())) {
    fwrite(STDOUT, "Role {$roleCode} already assigned to {$login} (user_id={$userId}).\n");
    exit(0);
}

$insert = $pdo->prepare(
    'INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by)
     VALUES (:user_id, :role_id, :tenant_id, :subtenant_id, :assigned_by)'
);
$insert->execute([
    ':user_id' => $userId,
    ':role_id' => (int) $role['id'],
    ':tenant_id' => $tenantId,
    ':subtenant_id' => $subtenantId,
    ':assigned_by' => $userId,
]);

fwrite(STDOUT, "Granted role {$roleCode} to {$login} (user_id={$userId}) @ {$tenantId}/{$subtenantId}\n");
