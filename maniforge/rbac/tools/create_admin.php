<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\PasswordService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

[$script, $login, $password, $email, $tenantId, $subtenantId, $roleCode] = array_pad($argv, 7, null);

if (!$login || !$password || !$email) {
    fwrite(STDERR, "Usage: php maniforge/rbac/tools/create_admin.php <login> <password> <email> [tenant] [subtenant] [role]\n");
    fwrite(STDERR, "Default role: tenant_admin. Use super_admin for platform operator.\n");
    exit(1);
}

$tenantId = $tenantId ?: ($_ENV['DEFAULT_TENANT_ID'] ?? 'default');
$subtenantId = $subtenantId ?: ($_ENV['DEFAULT_SUBTENANT_ID'] ?? 'default');
$roleCode = trim((string) ($roleCode ?: 'tenant_admin'));

$hash = (new PasswordService())->hash((string) $password);
$pdo = Connection::get();
$pdo->beginTransaction();

try {
    $stmt = $pdo->prepare(
        'INSERT INTO maniforge_users (
            tenant_id, subtenant_id, login, email, phone, password_hash, mfa_required, security_version, status
        ) VALUES (
            :tenant_id, :subtenant_id, :login, :email, :phone, :password_hash, 1, 1, "active"
        )'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':subtenant_id' => $subtenantId,
        ':login' => $login,
        ':email' => $email,
        ':phone' => '+70000000000',
        ':password_hash' => $hash,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $roleIdStmt = $pdo->prepare('SELECT id FROM maniforge_roles WHERE code = :code LIMIT 1');
    $roleIdStmt->execute([':code' => $roleCode]);
    $role = $roleIdStmt->fetch();
    if (!is_array($role)) {
        throw new RuntimeException("Role {$roleCode} not found. Import schema first.");
    }

    $attachRole = $pdo->prepare(
        'INSERT INTO maniforge_user_roles (user_id, role_id, tenant_id, subtenant_id, assigned_by)
         VALUES (:user_id, :role_id, :tenant_id, :subtenant_id, :assigned_by)'
    );
    $attachRole->execute([
        ':user_id' => $userId,
        ':role_id' => (int) $role['id'],
        ':tenant_id' => $tenantId,
        ':subtenant_id' => $subtenantId,
        ':assigned_by' => $userId,
    ]);

    $pdo->commit();
    fwrite(STDOUT, "User created: user_id={$userId}, login={$login}, role={$roleCode}, scope={$tenantId}::{$subtenantId}\n");
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
