<?php
declare(strict_types=1);

namespace App\Maniforge\Rbac\Repository;

use App\Database\Connection;

final class RateLimitRepository
{
    public function increment(string $bucketKey, int $windowSec): int
    {
        $pdo = Connection::get();

        try {
            $pdo->beginTransaction();

            $select = $pdo->prepare(
                'SELECT bucket_key,
                        window_started_at,
                        request_count,
                        TIMESTAMPDIFF(SECOND, window_started_at, UTC_TIMESTAMP()) AS window_age_sec
                 FROM maniforge_rate_limits
                 WHERE bucket_key = :bucket_key
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute([':bucket_key' => $bucketKey]);
            $row = $select->fetch();

            if (!is_array($row)) {
                $insert = $pdo->prepare(
                    'INSERT INTO maniforge_rate_limits (bucket_key, window_started_at, request_count, updated_at)
                     VALUES (:bucket_key, UTC_TIMESTAMP(), 1, UTC_TIMESTAMP())'
                );
                $insert->execute([':bucket_key' => $bucketKey]);
                $pdo->commit();

                return 1;
            }

            $windowExpired = (int) ($row['window_age_sec'] ?? 0) >= $windowSec;
            $count = $windowExpired ? 1 : ((int) $row['request_count'] + 1);

            $update = $pdo->prepare(
                'UPDATE maniforge_rate_limits
                 SET window_started_at = IF(:window_expired = 1, UTC_TIMESTAMP(), window_started_at),
                     request_count = :request_count,
                     updated_at = UTC_TIMESTAMP()
                 WHERE bucket_key = :bucket_key'
            );
            $update->execute([
                ':bucket_key' => $bucketKey,
                ':window_expired' => $windowExpired ? 1 : 0,
                ':request_count' => $count,
            ]);
            $pdo->commit();

            return $count;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
