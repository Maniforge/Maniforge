<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\Rbac\Repository\UserRepository;
use App\Maniforge\Rbac\Security\PiiFieldCodec;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$codec = new PiiFieldCodec();
if (!$codec->isEnabled()) {
    fwrite(STDERR, "Set RBAC_PII_ENCRYPTION_ENABLED=true and RBAC_PII_ENCRYPTION_KEY (32 bytes base64).\n");
    exit(1);
}
if (!$codec->hasValidKey()) {
    fwrite(STDERR, "Invalid RBAC_PII_ENCRYPTION_KEY. Generate: php -r \"echo base64_encode(random_bytes(32));\"\n");
    exit(1);
}

$batch = max(1, (int) ($argv[1] ?? 200));
$dryRun = in_array('--dry-run', $argv, true);
$users = new UserRepository($codec);

$afterId = 0;
$migrated = 0;

while (true) {
    $rows = $users->listPlaintextPiiBatch($afterId, $batch);
    if ($rows === []) {
        break;
    }

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $afterId = $id;
        $tenantId = (string) ($row['tenant_id'] ?? '');
        $subtenantId = (string) ($row['subtenant_id'] ?? '');
        $rawEmail = $row['email'] ?? null;
        $email = is_string($rawEmail) && $rawEmail !== '' && !ctype_xdigit($rawEmail) ? $rawEmail : null;
        if (is_string($rawEmail) && strlen($rawEmail) === 64 && ctype_xdigit($rawEmail)) {
            $email = null;
        }
        $phone = (string) ($row['phone'] ?? '');
        if ($phone === '' || (strlen($phone) === 64 && ctype_xdigit($phone))) {
            fwrite(STDERR, "[SKIP] user {$id}: phone missing or already blind index\n");
            continue;
        }

        if ($dryRun) {
            fwrite(STDOUT, "[DRY] Would encrypt user {$id} ({$tenantId}/{$subtenantId})\n");
            $migrated++;
            continue;
        }

        $users->upgradeRowToEncrypted($id, $email, $phone, $tenantId, $subtenantId);
        fwrite(STDOUT, "[OK] Encrypted user {$id}\n");
        $migrated++;
    }
}

fwrite(STDOUT, "Done. Migrated: {$migrated}" . ($dryRun ? ' (dry-run)' : '') . "\n");
