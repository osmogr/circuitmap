<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ReportCsvFlowTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    /**
     * @return array{ownerId: int, uuid: string}
     */
    private function seedCircuit(): array
    {
        $ownerId = $this->createUser('owner', 'editor');

        $this->pdo->exec(
            "INSERT INTO circuit_providers (name, is_active, created_at, updated_at)
             VALUES ('ProviderX', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );
        $providerId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO locations (name, is_active, created_at, updated_at)
             VALUES ('Site A', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z')"
        );
        $locationId = (int) $this->pdo->lastInsertId();

        $uuid = Uuid::v4();
        (new FileStorageService($this->storagePath))->saveNew($uuid, '<kml/>');
        $this->pdo->prepare(
            'INSERT INTO circuits (uuid, name, description, owner_id, current_file_path,
                 uploaded_at, updated_at, provider_id, a_location_id, z_location_id, provider_circuit_id)
             VALUES (:uuid, :name, :description, :owner, :path, :now, :now, :provider, :location, :location, :pcid)'
        )->execute([
            'uuid' => $uuid,
            'name' => 'Circuit CSV, "quoted"',
            'description' => 'has,comma',
            'owner' => $ownerId,
            'path' => "circuits/{$uuid}/current.kml",
            'now' => '2026-01-02T00:00:00Z',
            'provider' => $providerId,
            'location' => $locationId,
            'pcid' => 'PCID-7',
        ]);

        return ['ownerId' => $ownerId, 'uuid' => $uuid];
    }

    private function exportCsv(?int $userId = null): \Psr\Http\Message\ResponseInterface
    {
        if ($userId !== null) {
            $_SESSION = ['user_id' => $userId];
        }
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/circuits/report.csv');
        return $this->makeCircuitController()
            ->exportCsv($request, (new ResponseFactory())->createResponse());
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function parseCsv(string $body): array
    {
        $rows = [];
        foreach (explode("\n", trim($body)) as $line) {
            $rows[] = str_getcsv($line);
        }
        return $rows;
    }

    public function testCsvContainsAllCircuitColumnsAndJoinedNames(): void
    {
        $this->seedCircuit();

        $response = $this->exportCsv();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="circuitmap-circuits-', $response->getHeaderLine('Content-Disposition'));
        $body = (string) $response->getBody();
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));

        [$header, $row] = $this->parseCsv($body);

        foreach ($this->pdo->query('PRAGMA table_info(circuits)')->fetchAll() as $column) {
            $this->assertContains((string) $column['name'], $header);
        }
        foreach (['provider_name', 'a_location_name', 'z_location_name'] as $joined) {
            $this->assertContains($joined, $header);
        }

        $record = array_combine($header, $row);
        $this->assertSame('Circuit CSV, "quoted"', $record['name']);
        $this->assertSame('has,comma', $record['description']);
        $this->assertSame('ProviderX', $record['provider_name']);
        $this->assertSame('Site A', $record['a_location_name']);
        $this->assertSame('Site A', $record['z_location_name']);
        $this->assertSame('PCID-7', $record['provider_circuit_id']);
    }

    public function testCsvExcludesSoftDeletedCircuits(): void
    {
        $this->seedCircuit();
        $this->pdo->exec("UPDATE circuits SET deleted_at = '2026-01-03T00:00:00Z'");

        $rows = $this->parseCsv((string) $this->exportCsv()->getBody());

        $this->assertCount(1, $rows, 'only the header row should remain');
    }

    public function testCsvHasHeaderRowWhenInstanceIsEmpty(): void
    {
        $rows = $this->parseCsv((string) $this->exportCsv()->getBody());

        $this->assertCount(1, $rows);
        $this->assertContains('uuid', $rows[0]);
        $this->assertContains('provider_name', $rows[0]);
    }

    public function testCsvExportIsAuditLoggedWithUser(): void
    {
        $seed = $this->seedCircuit();

        $this->exportCsv($seed['ownerId']);

        $entries = $this->pdo->query("SELECT * FROM audit_log WHERE event_type = 'export'")->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertSame($seed['ownerId'], (int) $entries[0]['user_id']);
        $this->assertStringContainsString('format=csv circuits=1', (string) $entries[0]['detail']);
    }

    public function testCsvExportLogsNullUserWhenAnonymous(): void
    {
        $this->seedCircuit();

        $this->exportCsv();

        $entries = $this->pdo->query("SELECT * FROM audit_log WHERE event_type = 'export'")->fetchAll();
        $this->assertCount(1, $entries);
        $this->assertNull($entries[0]['user_id']);
    }
}
