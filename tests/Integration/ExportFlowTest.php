<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\ExportController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Kml\KmlExportService;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ExportFlowTest extends DatabaseTestCase
{
    private ExportController $controller;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->createUser('admin', 'admin');
        $circuits = new CircuitRepository($this->pdo);
        $storage = new FileStorageService($this->storagePath);

        $uuid = Uuid::v4();
        $storage->saveNew(
            $uuid,
            '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
            . '<Point><coordinates>-122.3,47.5</coordinates></Point>'
            . '</Placemark></Document></kml>'
        );
        $circuits->insert($uuid, 'Circuit A', null, null, $this->adminId, $storage->relativePath($uuid));

        $this->controller = new ExportController(
            new KmlExportService($circuits, $storage, new KmlParser()),
            new AuditLogRepository($this->pdo)
        );
    }

    private function exportRequest(string $format): \Psr\Http\Message\ResponseInterface
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $this->adminId]);
        $admin = $stmt->fetch();

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/admin/export/circuits.' . $format)
            ->withAttribute('currentUser', $admin);

        return $this->controller->exportCircuits(
            $request,
            (new ResponseFactory())->createResponse(),
            ['format' => $format]
        );
    }

    public function testKmlExportReturnsAttachmentWithValidKml(): void
    {
        $response = $this->exportRequest('kml');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.google-earth.kml+xml', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('attachment; filename="circuitmap-export-', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('.kml"', $response->getHeaderLine('Content-Disposition'));

        $body = (string) $response->getBody();
        $this->assertSame((string) strlen($body), $response->getHeaderLine('Content-Length'));
        $this->assertStringStartsWith('<?xml', $body);
        $this->assertStringContainsString('Circuit A', $body);
        (new KmlParser())->parse($body);
    }

    public function testKmzExportReturnsZipAttachment(): void
    {
        $response = $this->exportRequest('kmz');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.google-earth.kmz', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('.kmz"', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringStartsWith('PK', (string) $response->getBody());
    }

    public function testExportIsAuditLogged(): void
    {
        $this->exportRequest('kmz');

        $stmt = $this->pdo->query("SELECT * FROM audit_log WHERE event_type = 'export'");
        $entries = $stmt->fetchAll();

        $this->assertCount(1, $entries);
        $this->assertSame($this->adminId, (int) $entries[0]['user_id']);
        $this->assertStringContainsString('format=kmz', (string) $entries[0]['detail']);
        $this->assertStringContainsString('circuits=1', (string) $entries[0]['detail']);
        $this->assertStringContainsString('skipped=0', (string) $entries[0]['detail']);
    }
}
