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
use CircuitMap\Tests\Support\FakeGeocodingService;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises LocationController directly (real DB), same scope as the other
 * flow tests: business logic against a real database, not the full
 * HTTP/auth/CSRF/role-middleware stack. Real Nominatim network calls and
 * all Leaflet map interaction (drag/click/reveal) are manual-only, covered
 * separately by verification against the running container - same
 * convention as UploadFlowTest's auth/CSRF gating note.
 */
final class LocationAdminFlowTest extends DatabaseTestCase
{
    private LocationController $controller;
    private LocationRepository $locations;
    private FakeGeocodingService $geocoding;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->createUser('admin', 'admin');
        $this->locations = new LocationRepository($this->pdo);
        $this->geocoding = new FakeGeocodingService();

        $this->controller = new LocationController(
            $this->locations,
            new AuditLogRepository($this->pdo),
            new CsrfService(),
            $this->geocoding
        );
    }

    private function requestWithBody(string $method, string $uri, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri)
            ->withParsedBody($body)
            ->withAttribute('currentUser', ['id' => $this->adminId, 'username' => 'admin', 'role' => 'admin']);
    }

    public function testGeocodeAddressReturnsCoordinatesOnMatch(): void
    {
        $this->geocoding->setResult(['latitude' => 39.781721, 'longitude' => -89.650148]);

        $request = $this->requestWithBody('POST', '/admin/locations/geocode', ['address' => '123 Main St']);
        $response = $this->controller->geocodeAddress($request, (new ResponseFactory())->createResponse());

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertEqualsWithDelta(39.781721, $body['latitude'], 0.0001);
        $this->assertEqualsWithDelta(-89.650148, $body['longitude'], 0.0001);
        $this->assertSame('123 Main St', $this->geocoding->lastAddress);
    }

    public function testGeocodeAddressReturns404WhenNotFound(): void
    {
        $this->geocoding->setResult(null);

        $request = $this->requestWithBody('POST', '/admin/locations/geocode', ['address' => 'nowhere at all']);
        $response = $this->controller->geocodeAddress($request, (new ResponseFactory())->createResponse());

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('error', $body);
    }

    public function testGeocodeAddressReturns422WhenAddressMissing(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations/geocode', []);
        $response = $this->controller->geocodeAddress($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $this->geocoding->callCount);
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

    public function testCreateLocationPersistsMapFields(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations', [
            'name' => 'Main Street DC',
            'address' => '123 Main St, Springfield',
            'latitude' => '39.781721',
            'longitude' => '-89.650148',
            'icon' => 'data-center',
        ]);

        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $location = $this->locations->listAll()[0];
        $this->assertEqualsWithDelta(39.781721, (float) $location['latitude'], 0.0001);
        $this->assertEqualsWithDelta(-89.650148, (float) $location['longitude'], 0.0001);
        $this->assertSame('data-center', $location['icon']);
    }

    public function testCreateLocationRejectsPartialCoordinates(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations', [
            'name' => 'Main Street DC',
            'latitude' => '39.781721',
        ]);

        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->locations->listAll());
    }

    public function testCreateLocationRejectsUnknownIcon(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations', [
            'name' => 'Main Street DC',
            'latitude' => '39.781721',
            'longitude' => '-89.650148',
            'icon' => 'not-a-real-icon',
        ]);

        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->locations->listAll());
    }

    public function testListJsonOnlyIncludesActiveLocationsWithCoordinates(): void
    {
        $this->locations->insert('No address', null, null);
        $this->locations->insert('Geolocated', '123 Main St', null, 39.78, -89.65, 'office');
        $inactiveId = $this->locations->insert('Inactive but geolocated', '456 Elm St', null, 40.0, -90.0, 'office');
        $this->locations->setActive($inactiveId, false);

        $response = $this->controller->listJson(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/locations'),
            (new ResponseFactory())->createResponse()
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->assertCount(1, $body['locations']);
        $this->assertSame('Geolocated', $body['locations'][0]['name']);
        $this->assertSame('🏢', $body['locations'][0]['iconSymbol']);
        $this->assertIsFloat($body['locations'][0]['latitude']);
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

    public function testCreateLocationPersistsCactiHostIdWithUnknownStatus(): void
    {
        $request = $this->requestWithBody('POST', '/admin/locations', [
            'name' => 'Main Street DC',
            'cacti_host_id' => '42',
        ]);

        $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());
        $location = $this->locations->listAll()[0];
        $this->assertSame(42, (int) $location['cacti_host_id']);
        $this->assertSame('unknown', $location['status']);
        $this->assertNull($location['status_updated_at']);
    }

    public function testCreateLocationRejectsInvalidCactiHostId(): void
    {
        foreach (['-5', 'abc', '0', 3.7] as $bad) {
            $request = $this->requestWithBody('POST', '/admin/locations', [
                'name' => 'Main Street DC',
                'cacti_host_id' => $bad,
            ]);

            $response = $this->controller->createLocation($request, (new ResponseFactory())->createResponse());

            $this->assertSame(422, $response->getStatusCode(), 'value should be rejected: ' . var_export($bad, true));
        }
        $this->assertCount(0, $this->locations->listAll());
    }

    public function testUpdateChangingOrClearingCactiHostIdResetsStatus(): void
    {
        $locationId = $this->locations->insert('Main Street DC', null, null, null, null, null, 42);
        $this->locations->updateStatusFromPoller($locationId, 'up');

        // Saving with the same device id keeps the polled status.
        $this->updateLocationRequest($locationId, ['name' => 'Main Street DC', 'cacti_host_id' => '42']);
        $location = $this->locations->findById($locationId);
        $this->assertSame('up', $location['status'], 'unchanged mapping keeps polled status');

        // Pointing at a different device invalidates the old status.
        $this->updateLocationRequest($locationId, ['name' => 'Main Street DC', 'cacti_host_id' => '43']);
        $location = $this->locations->findById($locationId);
        $this->assertSame('unknown', $location['status'], 'changed mapping resets status');
        $this->assertNull($location['status_updated_at']);

        // Clearing the mapping also clears the status.
        $this->locations->updateStatusFromPoller($locationId, 'up');
        $this->updateLocationRequest($locationId, ['name' => 'Main Street DC', 'cacti_host_id' => '']);
        $location = $this->locations->findById($locationId);
        $this->assertNull($location['cacti_host_id']);
        $this->assertSame('unknown', $location['status'], 'cleared mapping resets status');
    }

    public function testListJsonIncludesStatusAndStatusColor(): void
    {
        $locationId = $this->locations->insert('Geolocated', '123 Main St', null, 39.78, -89.65, 'office', 42);
        $this->locations->updateStatusFromPoller($locationId, 'up');

        $response = $this->controller->listJson(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/locations'),
            (new ResponseFactory())->createResponse()
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('up', $body['locations'][0]['status']);
        $this->assertSame('#16a34a', $body['locations'][0]['statusColor']);
    }

    private function updateLocationRequest(int $locationId, array $body): void
    {
        $response = $this->controller->updateLocation(
            $this->requestWithBody('POST', "/admin/locations/{$locationId}", $body),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $locationId]
        );
        $this->assertSame(302, $response->getStatusCode());
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
