<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/bootstrap.php';

use App\Database\Connection;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run in CLI only.\n");
    exit(1);
}

$migrationsDir = dirname(__DIR__) . '/migrations';
$files = glob($migrationsDir . '/*.sql');
if ($files === false || $files === []) {
    fwrite(STDOUT, "No migrations found.\n");
    exit(0);
}
sort($files);

$pdo = Connection::get();
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS maniforge_migrations (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        version VARCHAR(64) NOT NULL UNIQUE,
        executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

function migrationColumnExists(PDO $pdo, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = "maniforge_migrations"
           AND column_name = :column_name'
    );
    $stmt->execute([':column_name' => $column]);
    $row = $stmt->fetch();

    return ((int) ($row['total'] ?? 0)) > 0;
}

function migrationTrackingReady(PDO $pdo): bool
{
    return migrationColumnExists($pdo, 'checksum')
        && migrationColumnExists($pdo, 'dirty')
        && migrationColumnExists($pdo, 'error_message')
        && migrationColumnExists($pdo, 'started_at')
        && migrationColumnExists($pdo, 'finished_at');
}

$trackingReady = migrationTrackingReady($pdo);
$appliedRows = $pdo->query('SELECT * FROM maniforge_migrations')->fetchAll(PDO::FETCH_ASSOC);
$appliedMap = [];
foreach ($appliedRows as $row) {
    $version = (string) ($row['version'] ?? '');
    if ($version === '') {
        continue;
    }
    if ($trackingReady && (int) ($row['dirty'] ?? 0) === 1) {
        fwrite(STDERR, "Dirty migration detected: {$version}. Resolve before applying new migrations.\n");
        exit(1);
    }
    $appliedMap[$version] = $row;
}

foreach ($files as $file) {
    $version = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Cannot read {$version}\n");
        exit(1);
    }
    $checksum = hash('sha256', $sql);

    if (isset($appliedMap[$version])) {
        $storedChecksum = (string) ($appliedMap[$version]['checksum'] ?? '');
        if ($storedChecksum !== '' && !hash_equals($storedChecksum, $checksum)) {
            fwrite(STDERR, "Checksum mismatch for applied migration {$version}\n");
            exit(1);
        }
        fwrite(STDOUT, "Skip {$version}\n");
        continue;
    }

    try {
        if ($trackingReady) {
            $stmt = $pdo->prepare(
                'INSERT INTO maniforge_migrations (version, checksum, dirty, started_at)
                 VALUES (:version, :checksum, 1, UTC_TIMESTAMP())'
            );
            $stmt->execute([':version' => $version, ':checksum' => $checksum]);
        }

        if (!$trackingReady) {
            $pdo->beginTransaction();
        }
        $pdo->exec($sql);
        $trackingReady = migrationTrackingReady($pdo);
        if ($trackingReady) {
            $stmt = $pdo->prepare(
                'INSERT INTO maniforge_migrations (version, checksum, dirty, started_at, finished_at)
                 VALUES (:version, :checksum, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                    checksum = VALUES(checksum),
                    dirty = 0,
                    error_message = NULL,
                    finished_at = UTC_TIMESTAMP()'
            );
            $stmt->execute([':version' => $version, ':checksum' => $checksum]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO maniforge_migrations (version) VALUES (:version)');
            $stmt->execute([':version' => $version]);
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        }
        fwrite(STDOUT, "Applied {$version}\n");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($trackingReady) {
            $stmt = $pdo->prepare(
                'UPDATE maniforge_migrations
                 SET dirty = 1, error_message = :error_message, finished_at = UTC_TIMESTAMP()
                 WHERE version = :version'
            );
            $stmt->execute([
                ':version' => $version,
                ':error_message' => substr($e->getMessage(), 0, 60000),
            ]);
        }
        fwrite(STDERR, "Failed {$version}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Done.\n");
