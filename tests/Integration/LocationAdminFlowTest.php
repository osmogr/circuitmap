<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\LocationController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises LocationController directly (real DB), same scope as the other
 * flow tests: business logic against a real database, not the full
 * HTTP/auth/CSRF/role-middleware stack.
 */
final class LocationAdminFlowTest extends DatabaseTestCase
{
    private LocationController $controller;
    private LocationRepository $locations;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->createUser('admin', 'admin');
        $this->locations = new LocationRepository($this->pdo);

        $this->controller = new LocationController(
            $this->locations,
            new AuditLogRepository($this->pdo),
            new CsrfService()
        );
    }

    private function requestWithBody(string $method, string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withParsedBody($body)
            ->withAttribute('currentUser', ['id' => $this->adminId, 'username' => 'admin', 'role' => 'admin']);
    }

    public function testCreateLocationPersistsAllFields(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations', [
            'name' => 'Main Street DC',
            'address' => '123 Main St, Springfield',
            'notes' => 'Loading dock on the north side',
        ]);

        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $locations = $this->locations->listAll();
        $this->assertCount(1, $locations);
        $this->assertSame('Main Street DC', $locations[0]['name']);
        $this->assertSame('123 Main St, Springfield', $locations[0]['address']);
        $this->assertSame('Loading dock on the north side', $locations[0]['notes']);
        $this->assertSame(1, (int) $locations[0]['is_active']);
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->locations->insert('Main Street DC', null, null);

        $request = $this->requestWithBody('POST', '/admin/locations', ['name' => 'Main Street DC']);
        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->locations->listAll());
    }

    public function testUpdateLocationChangesFields(): void
    {
        $locationId = $this->locations->insert('Main Street DC', null, null);

        $request = $this->requestWithBody('POST', "/admin/locations/{$locationId}", [
            'name' => 'Main Street DC (relocated)',
            'address' => '456 Elm St',
            'notes' => 'New address',
        ]);

        $response = $this->controller->updateLocation(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $locationId]
        );

        $this->assertSame(302, $response->getStatusCode());

        $location = $this->locations->findById($locationId);
        $this->assertSame('Main Street DC (relocated)', $location['name']);
        $this->assertSame('456 Elm St', $location['address']);
        $this->assertSame('New address', $location['notes']);
    }

    public function testDeactivateRemovesLocationFromActiveListButKeepsExistingReference(): void
    {
        $locationId = $this->locations->insert('Main Street DC', null, null);

        $circuits = new CircuitRepository($this->pdo);
        $storage = new FileStorageService($this->storagePath);
        $uuid = Uuid::v4();
        $storage->saveNew($uuid, '<kml/>');
        $circuits->insert(
            $uuid,
            'Circuit using location',
            null,
            null,
            $this->adminId,
            $storage->relativePath($uuid),
            null,
            null,
            null,
            false,
            $locationId
        );

        $request = $this->requestWithBody('POST', "/admin/locations/{$locationId}/active", ['active' => '0']);
        $response = $this->controller->setActive(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $locationId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(0, $this->locations->listActive());
        $this->assertCount(1, $this->locations->listAll());

        $circuit = $circuits->findByUuid($uuid);
        $this->assertSame($locationId, (int) $circuit['a_location_id']);
    }
}
