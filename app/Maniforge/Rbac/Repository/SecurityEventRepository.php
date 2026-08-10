<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class SecurityEventRepository
{
    public function write(
        string $eventType,
        ?int $userId,
        string $tenantId,
        string $subtenantId,
        string $severity,
        array $payload
    ): void {
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $correlationId = $this->correlationId($payload);
        $integrityHash = hash('sha256', implode('|', [
            $eventType,
            (string) ($userId ?? ''),
            $tenantId,
            $subtenantId,
            $severity,
            $payloadJson,
            $correlationId,
        ]));

        $stmt = Connection::get()->prepare(
            'INSERT INTO maniforge_security_events (
                event_type, user_id, tenant_id, subtenant_id, severity, payload_json, correlation_id, integrity_hash, created_at
            ) VALUES (
                :event_type, :user_id, :tenant_id, :subtenant_id, :severity, :payload_json, :correlation_id, :integrity_hash, UTC_TIMESTAMP()
            )'
        );
        $stmt->execute([
            ':event_type' => $eventType,
            ':user_id' => $userId,
            ':tenant_id' => $tenantId,
            ':subtenant_id' => $subtenantId,
            ':severity' => $severity,
            ':payload_json' => $payloadJson,
            ':correlation_id' => $correlationId,
            ':integrity_hash' => $integrityHash,
        ]);
    }

    /**
     * @param list<array{user_id: int, tenant_id: string, subtenant_id: string}> $scopes
     * @return array<string, string> scope key => last auth.login.success UTC datetime
     */
    public function findLastLoginSuccessAtByUserScopes(array $scopes): array
    {
        if ($scopes === []) {
            return [];
        }

        $conditions = [];
        $params = [':event_type' => 'auth.login.success'];
        foreach ($scopes as $index => $scope) {
            $conditions[] = "(user_id = :uid{$index} AND tenant_id = :tid{$index} AND subtenant_id = :sid{$index})";
            $params[":uid{$index}"] = (int) ($scope['user_id'] ?? 0);
            $params[":tid{$index}"] = (string) ($scope['tenant_id'] ?? '');
            $params[":sid{$index}"] = (string) ($scope['subtenant_id'] ?? '');
        }

        $stmt = Connection::get()->prepare(
            'SELECT user_id, tenant_id, subtenant_id, MAX(created_at) AS last_login_at
             FROM maniforge_security_events
             WHERE event_type = :event_type AND (' . implode(' OR ', $conditions) . ')
             GROUP BY user_id, tenant_id, subtenant_id'
        );
        $stmt->execute($params);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = SessionRepository::userScopeKey(
                (int) ($row['user_id'] ?? 0),
                (string) ($row['tenant_id'] ?? ''),
                (string) ($row['subtenant_id'] ?? '')
            );
            $lastLoginAt = (string) ($row['last_login_at'] ?? '');
            if ($key !== '' && $lastLoginAt !== '') {
                $result[$key] = $lastLoginAt;
            }
        }

        return $result;
    }

    public function listByScope(string $tenantId, string $subtenantId, int $limit = 100): array
    {
        $stmt = Connection::get()->prepare(
            'SELECT id, event_type, user_id, tenant_id, subtenant_id, severity, payload_json, correlation_id, integrity_hash, created_at
             FROM maniforge_security_events
             WHERE tenant_id = :tenant_id AND subtenant_id = :subtenant_id
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':subtenant_id', $subtenantId);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function correlationId(array $payload): string
    {
        $value = (string) ($payload['correlation_id'] ?? $_SERVER['HTTP_X_CORRELATION_ID'] ?? '');
        if ($value !== '' && preg_match('/^[a-f0-9]{32}$/i', $value) === 1) {
            return strtolower($value);
        }

        return bin2hex(random_bytes(16));
    }
}
