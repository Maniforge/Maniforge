<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Security;

use App\Database\Connection;
use App\Maniforge\Rbac\Repository\AuditLogRepository;
use App\Maniforge\Rbac\Repository\PdSubjectRequestRepository;

final class PdRetentionService
{
    public function __construct(
        private readonly AuditLogRepository $audit = new AuditLogRepository(),
    ) {
    }

    /**
     * @return array{overdue_requests_rejected: int, consents_revoked_by_retention: int, audit_purged: int}
     */
    public function enforce(): array
    {
        $pdo = Connection::get();
        $result = [
            'overdue_requests_rejected' => $this->rejectOverdueSubjectRequests($pdo),
            'consents_revoked_by_retention' => $this->revokeExpiredConsents($pdo),
            'audit_purged' => $this->purgeAuditIfConfigured($pdo),
        ];

        if ($result['overdue_requests_rejected'] > 0 || $result['consents_revoked_by_retention'] > 0 || $result['audit_purged'] > 0) {
            $this->audit->write('pd.retention.enforced', null, 'platform', 'system', $result);
        }

        return $result;
    }

    private function rejectOverdueSubjectRequests(\PDO $pdo): int
    {
        $stmt = $pdo->prepare(
            "UPDATE maniforge_pd_subject_requests
             SET status = 'rejected',
                 handler_note = 'auto: SLA exceeded',
                 completed_at = UTC_TIMESTAMP(),
                 updated_at = UTC_TIMESTAMP()
             WHERE status IN ('pending', 'in_progress')
               AND due_at IS NOT NULL
               AND due_at < UTC_TIMESTAMP()"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    private function revokeExpiredConsents(\PDO $pdo): int
    {
        if (!filter_var($_ENV['RBAC_PD_RETENTION_REVOKE_CONSENTS'] ?? 'true', FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }

        $stmt = $pdo->query(
            "SELECT c.id
             FROM maniforge_pd_consents c
             INNER JOIN maniforge_pd_processing_purposes p
               ON p.tenant_id = c.tenant_id AND p.code = c.purpose_code AND p.is_active = 1
             WHERE c.revoked_at IS NULL
               AND p.retention_days IS NOT NULL
               AND p.retention_days > 0
               AND c.granted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL p.retention_days DAY)"
        );
        $rows = $stmt->fetchAll() ?: [];
        if ($rows === []) {
            return 0;
        }

        $ids = array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows);
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $update = $pdo->prepare(
            "UPDATE maniforge_pd_consents
             SET revoked_at = UTC_TIMESTAMP(), source = 'retention_policy'
             WHERE id IN ({$placeholders}) AND revoked_at IS NULL"
        );
        $update->execute($ids);

        return $update->rowCount();
    }

    private function purgeAuditIfConfigured(\PDO $pdo): int
    {
        $auditDays = (int) ($_ENV['RBAC_AUDIT_RETENTION_DAYS'] ?? 0);
        if ($auditDays <= 0) {
            return 0;
        }

        $purge = $pdo->prepare(
            'DELETE FROM maniforge_audit_log WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :days DAY)'
        );
        $purge->bindValue(':days', $auditDays, \PDO::PARAM_INT);
        $purge->execute();

        return $purge->rowCount();
    }
}
