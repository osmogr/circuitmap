<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use CircuitMap\Support\Database;
use CircuitMap\Support\Env;

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename TEXT PRIMARY KEY,
        applied_at TEXT NOT NULL
    )'
);

$migrationsDir = Env::get('MIGRATIONS_PATH', dirname(__DIR__, 2) . '/migrations');
$files = glob($migrationsDir . '/*.sql');
if ($files === false) {
    fwrite(STDERR, "No migrations directory found at {$migrationsDir}\n");
    exit(1);
}
sort($files);

$appliedStmt = $pdo->query('SELECT filename FROM schema_migrations');
$applied = $appliedStmt !== false ? $appliedStmt->fetchAll(PDO::FETCH_COLUMN) : [];

foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "Could not read migration file {$file}\n");
        exit(1);
    }

    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $insert = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, ?)');
        $insert->execute([$filename, gmdate('Y-m-d\TH:i:s\Z')]);
        $pdo->commit();
        fwrite(STDOUT, "Applied migration: {$filename}\n");
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Migration failed: {$filename}: {$e->getMessage()}\n");
        exit(1);
    }
}

fwrite(STDOUT, "Migrations up to date.\n");
