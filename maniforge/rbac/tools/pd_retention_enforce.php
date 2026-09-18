<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;
use App\Maniforge\Rbac\Security\PdRetentionService;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$result = (new PdRetentionService())->enforce();

$pdo = Connection::get();
$pending = (int) $pdo->query(
    "SELECT COUNT(*) FROM maniforge_pd_subject_requests WHERE status IN ('pending', 'in_progress')"
)->fetchColumn();

fwrite(STDOUT, json_encode([
    'ok' => true,
    'subject_requests_overdue_rejected' => $result['overdue_requests_rejected'],
    'consents_revoked_by_retention' => $result['consents_revoked_by_retention'],
    'audit_rows_purged' => $result['audit_purged'],
    'audit_retention_days' => (int) ($_ENV['RBAC_AUDIT_RETENTION_DAYS'] ?? 0),
    'subject_requests_open' => $pending,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
