<?php
declare(strict_types=1);

namespace App\Maniforge\Warehouses\Repository;

use App\Database\Connection;
use App\Maniforge\Rbac\Support\PublicUserPayload;
use App\Maniforge\Rbac\Repository\UserRepository;

final class WarehouseAuditRepository
{
    private const EVENT_PREFIX = 'warehouses.';

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForStock(string $tenantId, string $subtenantId, int $stockId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 200));
        $stmt = Connection::get()->prepare(
            'SELECT id, event_type, actor_user_id, payload_json, correlation_id, integrity_hash, created_at
             FROM maniforge_audit_log
             WHERE tenant_id = :tenant_id
               AND subtenant_id = :subtenant_id
               AND event_type LIKE :prefix
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload_json, \'$.stock_id\')) AS UNSIGNED) = :stock_id
             ORDER BY id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':tenant_id', $tenantId);
        $stmt->bindValue(':subtenant_id', $subtenantId);
        $stmt->bindValue(':prefix', self::EVENT_PREFIX . '%');
        $stmt->bindValue(':stock_id', $stockId, \PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        $actorIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uid = (int) ($row['actor_user_id'] ?? 0);
            if ($uid > 0) {
                $actorIds[$uid] = true;
            }
        }
        $actors = $this->users->findManyByIdsInScope(array_keys($actorIds), $tenantId, $subtenantId);

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $payload = json_decode((string) ($row['payload_json'] ?? '{}'), true);
            if (!is_array($payload)) {
                $payload = [];
            }
            $actorId = (int) ($row['actor_user_id'] ?? 0);
            $item = [
                'id' => (int) ($row['id'] ?? 0),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'stock_id' => (int) ($payload['stock_id'] ?? $stockId),
                'payload' => $payload,
                'correlation_id' => (string) ($row['correlation_id'] ?? ''),
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
            if ($actorId > 0 && isset($actors[$actorId])) {
                $item['actor_user'] = PublicUserPayload::fromUser($actors[$actorId]);
            }

            $items[] = $item;
        }

        return $items;
    }
}
