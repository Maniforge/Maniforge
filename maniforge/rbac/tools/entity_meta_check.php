<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\EntityMetaRepository;
use App\Maniforge\Rbac\Security\EntityMetaTypes;
use App\Maniforge\Rbac\Security\RegistrationService;
use App\Maniforge\Rbac\Support\PublicUserPayload;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

final class EntityMetaAsserts
{
    public int $passed = 0;
    public int $failed = 0;

    public function ok(bool $cond, string $msg): void
    {
        if ($cond) {
            $this->passed++;
            fwrite(STDOUT, "[OK] {$msg}\n");
            return;
        }
        $this->failed++;
        fwrite(STDERR, "[FAIL] {$msg}\n");
    }

    public function same(mixed $expected, mixed $actual, string $msg): void
    {
        $this->ok(
            $expected === $actual,
            $msg . ' (expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true) . ')'
        );
    }
}

function cleanupEntityMetaPhones(string ...$phones): void
{
    $pdo = Connection::get();
    $stmt = $pdo->prepare('DELETE FROM maniforge_entity_meta WHERE type = :type AND meta = :meta');
    foreach ($phones as $phone) {
        if ($phone === '') {
            continue;
        }
        try {
            $stmt->execute([':type' => EntityMetaTypes::TYPE_PHONE, ':meta' => $phone]);
        } catch (Throwable) {
        }
    }
}

function cleanupEntityMetaTenant(string $tenantId): void
{
    $pdo = Connection::get();
    foreach ([
        'DELETE FROM maniforge_entity_meta WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_user_roles WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_users WHERE tenant_id = :tenant_id',
        'DELETE FROM maniforge_tl_tenant_licenses WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_subtenants WHERE tenant_code = :tenant_id',
        'DELETE FROM maniforge_tl_tenants WHERE code = :tenant_id',
    ] as $sql) {
        try {
            $pdo->prepare($sql)->execute([':tenant_id' => $tenantId]);
        } catch (Throwable) {
        }
    }
}

$assert = new EntityMetaAsserts();
$meta = new EntityMetaRepository();
$registration = new RegistrationService();
$suffix = substr(bin2hex(random_bytes(4)), 0, 8);
$phoneA = '+7908' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$phoneB = '+7909' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$phoneReg = '+7910' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
$tenantId = '';

try {
    Connection::get()->query('SELECT 1');
    $pdo = Connection::get();
$indexStmt = $pdo->query(
    "SHOW INDEX FROM maniforge_entity_meta WHERE Key_name = 'uk_entity_meta_scope'"
);
$assert->ok(is_array($indexStmt->fetch()), 'Unique index uk_entity_meta_scope exists');

$payload = PublicUserPayload::fromUser([
    'id' => 99,
    'login' => 'secret_login',
    'phone' => $phoneA,
    'email' => 'meta_' . $suffix . '@example.test',
    'status' => 'active',
]);
$assert->ok(!array_key_exists('login', $payload), 'PublicUserPayload omits login');
$assert->same($phoneA, (string) ($payload['phone'] ?? ''), 'PublicUserPayload exposes phone');

$meta->bindGlobalPhone($phoneA, 90001);
$meta->rebindPhoneForUser($phoneA, 90001, 't_meta_' . $suffix, 'main');
$globalId = $meta->findGlobalPhoneUserId($phoneA);
$assert->same(90001, $globalId, 'findGlobalPhoneUserId returns bound user');
$scoped = $meta->findInScope(EntityMetaTypes::TYPE_PHONE, $phoneA, EntityMetaTypes::I_USER, 't_meta_' . $suffix, 'main');
$assert->ok(is_array($scoped), 'findInScope returns tenant row');
$assert->same(90001, (int) ($scoped['i_id'] ?? 0), 'Scoped row i_id matches user');
$ids = $meta->internalIdsByMeta(EntityMetaTypes::TYPE_PHONE, $phoneA, EntityMetaTypes::I_USER);
$assert->ok(in_array(90001, $ids, true), 'internalIdsByMeta lists user id');

$meta->bindGlobalPhone($phoneB, 90002);
$assert->same(90002, $meta->findGlobalPhoneUserId($phoneB), 'Second global phone binding is independent');

$register = $registration->register([
    'password' => 'EntityMetaCheck!123',
    'phone' => $phoneReg,
    'email' => 'em_reg_' . $suffix . '@example.test',
    'organization_name' => 'Entity Meta Org ' . $suffix,
    'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
]);
$assert->ok(($register['ok'] ?? false) === true, 'Registration creates user and entity_meta');
$tenantId = (string) ($register['tenant']['tenant_id'] ?? '');
$userId = (int) ($register['user']['id'] ?? 0);
$assert->same($userId, $meta->findGlobalPhoneUserId($phoneReg), 'Registration binds global phone meta');
$dup = $registration->register([
    'password' => 'EntityMetaCheck!123',
    'phone' => $phoneReg,
    'organization_name' => 'Duplicate Org',
    'consents' => [['purpose_code' => 'account', 'policy_version' => '1.0']],
]);
$assert->ok(($dup['ok'] ?? true) === false, 'Duplicate global phone blocked on second tenant');
$assert->same(409, (int) ($dup['status'] ?? 0), 'Duplicate phone status 409');
$assert->same('phone_already_registered', (string) ($dup['code'] ?? ''), 'Duplicate phone code');

$prefixOnly = $registration->resolvePhoneFromInput([
    'phone_prefix' => '+7',
    'phone_number' => '9001234567',
]);
$assert->same('+79001234567', $prefixOnly, 'resolvePhoneFromInput merges prefix and number');
    $assert->same('', $registration->resolvePhoneFromInput(['login' => 'legacy_user']), 'login alone does not resolve phone');
} catch (Throwable $e) {
    $assert->ok(false, 'Unhandled exception: ' . $e->getMessage());
} finally {
    cleanupEntityMetaPhones($phoneA, $phoneB, $phoneReg);
    if ($tenantId !== '') {
        cleanupEntityMetaTenant($tenantId);
    }
}

fwrite(STDOUT, "\nEntity meta checks passed: {$assert->passed}\n");
fwrite(STDOUT, "Entity meta checks failed: {$assert->failed}\n");
exit($assert->failed > 0 ? 1 : 0);
