<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Maniforge\TenantLicensing\Repository\TenantLicensingRepository;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$rbacUrl = rtrim((string) ($_ENV['RBAC_INTERNAL_URL'] ?? ''), '/');
if ($rbacUrl === '') {
    $addr = trim((string) ($_ENV['MANIFORGE_RBAC_ADDR'] ?? ''));
    if ($addr !== '') {
        if (str_starts_with($addr, ':')) {
            $addr = '127.0.0.1' . $addr;
        } elseif (str_starts_with($addr, '0.0.0.0:')) {
            $addr = '127.0.0.1:' . substr($addr, strlen('0.0.0.0:'));
        }
        $rbacUrl = 'http://' . $addr . '/rbac';
    }
}
if ($rbacUrl === '') {
    fwrite(STDERR, "RBAC_INTERNAL_URL unset and MANIFORGE_RBAC_ADDR missing; skip HTTP dispatch.\n");
    exit(0);
}

$repository = new TenantLicensingRepository();
$events = $repository->pendingEvents((int) ($_ENV['TENANT_LICENSING_EVENT_BATCH'] ?? 50));
$token = trim((string) ($_ENV['RBAC_INTERNAL_TOKEN'] ?? ''));
if ($token === '') {
    $token = (string) ($_ENV['TENANT_LICENSING_INTERNAL_TOKEN'] ?? '');
}
$sent = 0;
$failed = 0;

foreach ($events as $event) {
    $payload = [
        'event_type' => (string) $event['event_type'],
        'tenant_code' => (string) $event['tenant_code'],
        'subtenant_code' => $event['subtenant_code'],
        'payload' => json_decode((string) $event['payload_json'], true) ?: [],
    ];
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'timeout' => max(1, (int) ($_ENV['TENANT_LICENSING_TIMEOUT_SEC'] ?? 2)),
            'ignore_errors' => true,
        ],
    ]);
    $body = file_get_contents($rbacUrl . '/internal/v1/tenant-events', false, $context);
    $decoded = $body === false ? null : json_decode($body, true);
    if (is_array($decoded) && ($decoded['ok'] ?? false) === true) {
        $repository->ackEvent((int) $event['id']);
        $sent++;
        continue;
    }

    $failed++;
    fwrite(STDERR, "Failed to dispatch event {$event['id']}\n");
}

fwrite(STDOUT, "Dispatched={$sent}, failed={$failed}\n");
exit($failed > 0 ? 1 : 0);
