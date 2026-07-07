<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Support;

use CircuitMap\Controllers\CircuitController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlFolderSplitter;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Services\Storage\PendingImportStorage;
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

    /**
     * Real controller with real dependencies against the test DB and
     * storage — the wiring mirror of App::buildServices().
     */
    protected function makeCircuitController(): CircuitController
    {
        return new CircuitController(
            $this->pdo,
            new AuthService(new UserRepository($this->pdo)),
            new CsrfService(),
            new CircuitRepository($this->pdo),
            new CircuitProviderRepository($this->pdo),
            new LocationRepository($this->pdo),
            new AuditLogRepository($this->pdo),
            new FileStorageService($this->storagePath),
            new KmlParser(),
            new KmlValidator(),
            new KmlSanitizer(),
            new GeoJsonConverter(),
            new KmzExtractor(),
            new KmlFolderSplitter(),
            new PendingImportStorage($this->storagePath)
        );
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
