<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\InstanceTransferController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Instance\InstanceExportService;
use CircuitMap\Services\Instance\InstanceImportException;
use CircuitMap\Services\Instance\InstanceImportService;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use ZipArchive;

/**
 * Exercises the full instance export/import cycle: the source instance is
 * the DatabaseTestCase DB/storage, targets are separate throwaway DB files
 * and storage roots built per test. $_SESSION is a plain array under CLI,
 * same approach as ProxyAuthFlowTest.
 */
final class InstanceTransferFlowTest extends DatabaseTestCase
{
    private const KML = '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
        . '<Point><coordinates>-122.3,47.5</coordinates></Point>'
        . '</Placemark></Document></kml>';

    private FileStorageService $storage;
    /** @var array<int, string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSchemaMigrations($this->pdo);
        $this->storage = new FileStorageService($this->storagePath);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        foreach ($this->cleanupPaths as $path) {
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeTree($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Seeds the source instance: two users, a provider, a location, one
     * live circuit with an archived version, one soft-deleted circuit and
     * an audit entry.
     *
     * @return array{adminId: int, liveUuid: string, deletedUuid: string}
     */
    private function seedSource(): array
    {
        $adminId = $this->createUser('greg', 'admin');
        $editorId = $this->createUser('bob', 'editor');

        $this->pdo->exec(
            "INSERT INTO circuit_providers (name, tech_support_number, account_id, is_active, created_at, updated_at)
             VALUES ('ProviderX', '555-0100', 'ACCT-1', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );
        $providerId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO locations (name, address, is_active, created_at, updated_at, latitude, longitude)
             VALUES ('Site A', '1 Main St', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z', 47.5, -122.3)"
        );
        $locationId = (int) $this->pdo->lastInsertId();

        $liveUuid = Uuid::v4();
        $this->storage->saveNew($liveUuid, self::KML);
        $this->storage->writeVersion($liveUuid, 1, '<kml>v1 snapshot</kml>');
        $insert = $this->pdo->prepare(
            'INSERT INTO circuits (uuid, name, description, owner_id, current_file_path, current_version,
                 status, uploaded_at, updated_at, provider_id, a_location_id, capacity_bps)
             VALUES (:uuid, :name, :description, :owner, :path, :version,
                 :status, :now, :now, :provider, :location, :capacity)'
        );
        $insert->execute([
            'uuid' => $liveUuid,
            'name' => 'Circuit Live',
            'description' => 'primary',
            'owner' => $adminId,
            'path' => "circuits/{$liveUuid}/current.kml",
            'version' => 2,
            'status' => 'up',
            'now' => '2026-01-02T00:00:00Z',
            'provider' => $providerId,
            'location' => $locationId,
            'capacity' => 1_000_000_000,
        ]);
        $liveCircuitId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO circuit_versions (circuit_id, version_number, file_path, name_snapshot, edited_by, created_at)
             VALUES (:circuit, 1, :path, :name, :editor, :now)'
        )->execute([
            'circuit' => $liveCircuitId,
            'path' => "circuits/{$liveUuid}/versions/v1.kml",
            'name' => 'Circuit Live',
            'editor' => $editorId,
            'now' => '2026-01-02T00:00:00Z',
        ]);

        $deletedUuid = Uuid::v4();
        $this->storage->saveNew($deletedUuid, self::KML);
        $insert->execute([
            'uuid' => $deletedUuid,
            'name' => 'Circuit Gone',
            'description' => null,
            'owner' => $editorId,
            'path' => "circuits/{$deletedUuid}/current.kml",
            'version' => 1,
            'status' => 'unknown',
            'now' => '2026-01-03T00:00:00Z',
            'provider' => null,
            'location' => null,
            'capacity' => null,
        ]);
        $this->pdo->exec("UPDATE circuits SET deleted_at = '2026-01-04T00:00:00Z' WHERE name = 'Circuit Gone'");

        (new AuditLogRepository($this->pdo))->log($adminId, 'upload', $liveCircuitId, 'seeded', '10.0.0.1');

        return ['adminId' => $adminId, 'liveUuid' => $liveUuid, 'deletedUuid' => $deletedUuid];
    }

    /**
     * A brand-new, migrated, empty target instance with its own bootstrap
     * admin — the state a fresh deployment is in right before an import.
     *
     * @return array{pdo: \PDO, storage: FileStorageService, storagePath: string, seedAdminId: int}
     */
    private function createFreshTarget(string $seedAdminUsername = 'seedadmin'): array
    {
        $dbPath = sys_get_temp_dir() . '/circuitmap-test-target-' . uniqid('', true) . '.sqlite';
        $this->cleanupPaths[] = $dbPath;

        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        foreach (glob(dirname(__DIR__, 2) . '/migrations/*.sql') ?: [] as $file) {
            $pdo->exec((string) file_get_contents($file));
        }
        $this->seedSchemaMigrations($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active, created_at)
             VALUES (:username, :hash, :role, 1, :now)'
        );
        $stmt->execute([
            'username' => $seedAdminUsername,
            'hash' => password_hash('irrelevant-in-tests', PASSWORD_DEFAULT),
            'role' => 'admin',
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
        $seedAdminId = (int) $pdo->lastInsertId();

        $storagePath = sys_get_temp_dir() . '/circuitmap-test-target-kml-' . uniqid('', true);
        mkdir($storagePath, 0770, true);
        $this->cleanupPaths[] = $storagePath;

        return [
            'pdo' => $pdo,
            'storage' => new FileStorageService($storagePath),
            'storagePath' => $storagePath,
            'seedAdminId' => $seedAdminId,
        ];
    }

    private function exportArchive(): string
    {
        $result = (new InstanceExportService($this->pdo, $this->storage))->buildArchive();
        $this->cleanupPaths[] = $result['path'];
        return $result['path'];
    }

    private function makeController(\PDO $pdo, FileStorageService $storage): InstanceTransferController
    {
        return new InstanceTransferController(
            new InstanceExportService($pdo, $storage),
            new InstanceImportService($pdo, $storage),
            new AuthService(new UserRepository($pdo)),
            new CsrfService(),
            new AuditLogRepository($pdo)
        );
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $currentUser
     */
    private function controllerImport(array $target, array $currentUser, string $zipPath): \Psr\Http\Message\ResponseInterface
    {
        // moveTo() renames the file away, so hand the controller a copy.
        $uploadPath = tempnam(sys_get_temp_dir(), 'circuitmap_test_upload_');
        $this->cleanupPaths[] = $uploadPath;
        copy($zipPath, $uploadPath);

        $upload = new \Slim\Psr7\UploadedFile(
            $uploadPath,
            'circuitmap-instance.zip',
            'application/zip',
            (int) filesize($uploadPath),
            UPLOAD_ERR_OK
        );
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/instance/import')
            ->withAttribute('currentUser', $currentUser)
            ->withParsedBody(['confirm' => 'REPLACE'])
            ->withUploadedFiles(['archive' => $upload]);

        return $this->makeController($target['pdo'], $target['storage'])
            ->import($request, (new ResponseFactory())->createResponse());
    }

    /**
     * @param callable(ZipArchive): void $mutate
     */
    private function tamperArchive(string $zipPath, callable $mutate): void
    {
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $mutate($zip);
        $zip->close();
    }

    /**
     * @return array<string, mixed>
     */
    private function userRow(\PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return (array) $stmt->fetch();
    }

    public function testExportProducesArchiveWithManifestDataAndKml(): void
    {
        $seed = $this->seedSource();

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/instance/export.zip')
            ->withAttribute('currentUser', $this->userRow($this->pdo, $seed['adminId']));
        $response = $this->makeController($this->pdo, $this->storage)
            ->export($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="circuitmap-instance-', $response->getHeaderLine('Content-Disposition'));

        $body = (string) $response->getBody();
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));

        $zipPath = tempnam(sys_get_temp_dir(), 'circuitmap_test_zip_');
        $this->cleanupPaths[] = $zipPath;
        file_put_contents($zipPath, $body);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath));

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $this->assertSame('circuitmap-instance-export', $manifest['format']);
        $this->assertSame(1, $manifest['format_version']);
        $this->assertSame(2, $manifest['counts']['users']);
        $this->assertSame(2, $manifest['counts']['circuits']);
        $this->assertSame(1, $manifest['counts']['circuit_versions']);
        $this->assertContains('001_create_users.sql', $manifest['migrations']);

        foreach (InstanceExportService::TABLES as $table) {
            $this->assertNotFalse($zip->getFromName("data/{$table}.json"), "missing data/{$table}.json");
        }
        $this->assertSame(self::KML, $zip->getFromName("kml/circuits/{$seed['liveUuid']}/current.kml"));
        $this->assertSame('<kml>v1 snapshot</kml>', $zip->getFromName("kml/circuits/{$seed['liveUuid']}/versions/v1.kml"));
        $this->assertSame(self::KML, $zip->getFromName("kml/circuits/{$seed['deletedUuid']}/current.kml"));
        $zip->close();

        $entries = $this->pdo->query("SELECT * FROM audit_log WHERE event_type = 'instance_export'")->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertSame($seed['adminId'], (int) $entries[0]['user_id']);
        $this->assertStringContainsString('circuits=2', (string) $entries[0]['detail']);
    }

    public function testRoundTripRestoresIdenticalData(): void
    {
        $seed = $this->seedSource();
        $zipPath = $this->exportArchive();
        $target = $this->createFreshTarget();

        $result = (new InstanceImportService($target['pdo'], $target['storage']))
            ->import($zipPath, 'greg');

        $this->assertSame('greg', $result['rebindUser']['username']);
        $this->assertSame($seed['adminId'], (int) $result['rebindUser']['id']);

        foreach (InstanceExportService::TABLES as $table) {
            $source = $this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
            $imported = $target['pdo']->query("SELECT * FROM {$table} ORDER BY id")->fetchAll();
            $this->assertSame($source, $imported, "{$table} rows differ after round trip");
        }

        $this->assertSame(self::KML, $target['storage']->read($seed['liveUuid']));
        $this->assertSame('<kml>v1 snapshot</kml>', $target['storage']->readVersion($seed['liveUuid'], 1));
        $this->assertSame(self::KML, $target['storage']->read($seed['deletedUuid']));

        $sourceSeqs = $this->pdo->query('SELECT name, seq FROM sqlite_sequence ORDER BY name')->fetchAll();
        foreach ($sourceSeqs as $row) {
            $stmt = $target['pdo']->prepare('SELECT seq FROM sqlite_sequence WHERE name = :name');
            $stmt->execute(['name' => $row['name']]);
            $this->assertGreaterThanOrEqual((int) $row['seq'], (int) $stmt->fetchColumn(), "sequence for {$row['name']}");
        }

        // Future inserts must not collide with imported ids.
        $maxId = (int) $target['pdo']->query('SELECT MAX(id) FROM users')->fetchColumn();
        $target['pdo']->exec(
            "INSERT INTO users (username, password_hash, role, is_active, created_at)
             VALUES ('after-import', 'x', 'editor', 1, '2026-01-05T00:00:00Z')"
        );
        $this->assertGreaterThan($maxId, (int) $target['pdo']->lastInsertId());
    }

    public function testImportRefusesWhenTargetHasCircuits(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();

        $target = $this->createFreshTarget();
        $uuid = Uuid::v4();
        $target['pdo']->exec(
            "INSERT INTO circuits (uuid, name, owner_id, current_file_path, uploaded_at, updated_at)
             VALUES ('{$uuid}', 'Existing', {$target['seedAdminId']}, 'circuits/{$uuid}/current.kml',
                 '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );

        try {
            (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
            $this->fail('Expected InstanceImportException');
        } catch (InstanceImportException $e) {
            $this->assertStringContainsString('fresh, empty instance', $e->getMessage());
        }

        $this->assertSame(1, (int) $target['pdo']->query('SELECT COUNT(*) FROM circuits')->fetchColumn());
        $this->assertSame(1, (int) $target['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertTrue($target['storage']->circuitsRootIsEmpty());
    }

    public function testImportRefusesWhenTargetHasMultipleUsers(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();

        $target = $this->createFreshTarget();
        $target['pdo']->exec(
            "INSERT INTO users (username, password_hash, role, is_active, created_at)
             VALUES ('second', 'x', 'editor', 1, '2026-01-01T00:00:00Z')"
        );

        $this->expectException(InstanceImportException::class);
        $this->expectExceptionMessageMatches('/user accounts beyond the bootstrap admin/');
        (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
    }

    public function testImportRefusesMigrationMismatch(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        $this->tamperArchive($zipPath, function (ZipArchive $zip): void {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            array_pop($manifest['migrations']);
            $zip->addFromString('manifest.json', json_encode($manifest));
        });

        $target = $this->createFreshTarget();

        $this->expectException(InstanceImportException::class);
        $this->expectExceptionMessageMatches('/different CircuitMap version/');
        (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
    }

    public function testImportRejectsPathTraversalEntries(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        $this->tamperArchive($zipPath, function (ZipArchive $zip): void {
            $zip->addFromString('kml/circuits/../../etc/passwd', 'evil');
        });

        $target = $this->createFreshTarget();

        try {
            (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
            $this->fail('Expected InstanceImportException');
        } catch (InstanceImportException $e) {
            $this->assertStringContainsString('path-traversal', $e->getMessage());
        }

        // Refused before any write.
        $this->assertSame(1, (int) $target['pdo']->query('SELECT COUNT(*) FROM users')->fetchColumn());
        $this->assertSame(0, (int) $target['pdo']->query('SELECT COUNT(*) FROM circuits')->fetchColumn());
        $this->assertTrue($target['storage']->circuitsRootIsEmpty());
    }

    public function testImportRejectsEntriesOutsideTheWhitelist(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        $this->tamperArchive($zipPath, function (ZipArchive $zip): void {
            $zip->addFromString('kml/circuits/not-a-uuid/current.kml', '<kml/>');
        });

        $target = $this->createFreshTarget();

        $this->expectException(InstanceImportException::class);
        $this->expectExceptionMessageMatches('/Unexpected entry/');
        (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
    }

    public function testImportRefusesArchiveWithoutActiveAdmin(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        $this->tamperArchive($zipPath, function (ZipArchive $zip): void {
            $users = json_decode((string) $zip->getFromName('data/users.json'), true);
            foreach ($users as &$user) {
                $user['role'] = 'editor';
            }
            $zip->addFromString('data/users.json', json_encode($users));
        });

        $target = $this->createFreshTarget();

        $this->expectException(InstanceImportException::class);
        $this->expectExceptionMessageMatches('/no active admin/');
        (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
    }

    public function testImportIsAllOrNothing(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        // A duplicate username passes row validation (ids are unique) but
        // trips the users.username UNIQUE constraint mid-restore, after the
        // target's own rows were already deleted inside the transaction.
        $this->tamperArchive($zipPath, function (ZipArchive $zip): void {
            $users = json_decode((string) $zip->getFromName('data/users.json'), true);
            $clone = $users[0];
            $clone['id'] = 999;
            $users[] = $clone;
            $zip->addFromString('data/users.json', json_encode($users));

            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            $manifest['counts']['users']++;
            $zip->addFromString('manifest.json', json_encode($manifest));
        });

        $target = $this->createFreshTarget();

        try {
            (new InstanceImportService($target['pdo'], $target['storage']))->import($zipPath, 'greg');
            $this->fail('Expected InstanceImportException');
        } catch (InstanceImportException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }

        // The rollback restored the seed admin and left nothing else behind.
        $users = $target['pdo']->query('SELECT username FROM users')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['seedadmin'], $users);
        $this->assertSame(0, (int) $target['pdo']->query('SELECT COUNT(*) FROM circuits')->fetchColumn());
        $this->assertSame(0, (int) $target['pdo']->query('SELECT COUNT(*) FROM audit_log')->fetchColumn());
        $this->assertTrue($target['storage']->circuitsRootIsEmpty());
    }

    public function testControllerImportRebindsSessionWhenUsernameMatches(): void
    {
        $seed = $this->seedSource();
        $zipPath = $this->exportArchive();

        // The target's bootstrap admin shares the username of an admin in
        // the archive, so the session survives under the imported identity.
        $target = $this->createFreshTarget('greg');
        $_SESSION = ['user_id' => $target['seedAdminId'], 'role' => 'admin'];

        $response = $this->controllerImport($target, $this->userRow($target['pdo'], $target['seedAdminId']), $zipPath);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/admin/instance?imported=1', $response->getHeaderLine('Location'));
        $this->assertSame($seed['adminId'], $_SESSION['user_id']);
        $this->assertSame('admin', $_SESSION['role']);

        $entries = $target['pdo']->query("SELECT * FROM audit_log WHERE event_type = 'instance_import'")->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertSame($seed['adminId'], (int) $entries[0]['user_id']);
        $this->assertStringContainsString('circuits=2', (string) $entries[0]['detail']);
    }

    public function testControllerImportLogsOutWhenUsernameMissing(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();

        $target = $this->createFreshTarget('seedadmin');
        $_SESSION = ['user_id' => $target['seedAdminId'], 'role' => 'admin'];

        $response = $this->controllerImport($target, $this->userRow($target['pdo'], $target['seedAdminId']), $zipPath);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/login', $response->getHeaderLine('Location'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);

        $entries = $target['pdo']->query("SELECT * FROM audit_log WHERE event_type = 'instance_import'")->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['user_id']);
        $this->assertStringContainsString('by_username=seedadmin', (string) $entries[0]['detail']);
    }

    public function testControllerImportRequiresConfirmation(): void
    {
        $this->seedSource();
        $zipPath = $this->exportArchive();
        $target = $this->createFreshTarget();

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/admin/instance/import')
            ->withAttribute('currentUser', $this->userRow($target['pdo'], $target['seedAdminId']))
            ->withParsedBody(['confirm' => 'nope']);
        $response = $this->makeController($target['pdo'], $target['storage'])
            ->import($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Type REPLACE', (string) $response->getBody());
        $this->assertSame(0, (int) $target['pdo']->query('SELECT COUNT(*) FROM circuits')->fetchColumn());
    }
}
