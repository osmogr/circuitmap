<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Support;

use CircuitMap\Support\Database;
use CircuitMap\Support\View;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;
    protected string $dbPath;
    protected string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/circuitmap-test-' . uniqid('', true) . '.sqlite';
        putenv('DB_PATH=' . $this->dbPath);
        Database::reset();
        $this->pdo = Database::connection();

        $migrationsDir = dirname(__DIR__, 2) . '/migrations';
        foreach (glob($migrationsDir . '/*.sql') ?: [] as $file) {
            $this->pdo->exec((string) file_get_contents($file));
        }

        $this->storagePath = sys_get_temp_dir() . '/circuitmap-test-kml-' . uniqid('', true);
        mkdir($this->storagePath, 0770, true);

        View::setTemplatesPath('/var/www/app/templates');
    }

    protected function tearDown(): void
    {
        Database::reset();
        @unlink($this->dbPath);
        $this->removeDirectory($this->storagePath);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function createUser(string $username = 'testuser', string $role = 'editor'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, created_at)
             VALUES (:username, :hash, :role, 1, :now)'
        );
        $stmt->execute([
            'username' => $username,
            'hash' => password_hash('irrelevant-in-tests', PASSWORD_DEFAULT),
            'role' => $role,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
