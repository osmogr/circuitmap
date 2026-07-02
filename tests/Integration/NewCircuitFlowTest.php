<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Controllers\CircuitController;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises CircuitController::createBlank()/showNewForm() directly, same
 * scope as UploadFlowTest: business logic against a real DB and real file
 * storage, not the full HTTP/auth/CSRF stack.
 */
final class NewCircuitFlowTest extends DatabaseTestCase
{
    private CircuitController $controller;
    private CircuitRepository $circuits;
    private CircuitProviderRepository $providers;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);
        $this->providers = new CircuitProviderRepository($this->pdo);

        $this->controller = new CircuitController(
            new AuthService(new UserRepository($this->pdo)),
            new CsrfService(),
            $this->circuits,
            $this->providers,
            new AuditLogRepository($this->pdo),
            new FileStorageService($this->storagePath),
            new KmlParser(),
            new KmlValidator(),
            new KmlSanitizer(),
            new GeoJsonConverter(),
            new KmzExtractor()
        );
    }

    private function requestWithBody(array $formFields)
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/circuits/new')
            ->withParsedBody($formFields)
            ->withAttribute('currentUser', ['id' => $this->userId, 'username' => 'testuser', 'role' => 'editor']);
    }

    public function testCreateBlankCreatesEmptyCircuitAndRedirectsToEditor(): void
    {
        $request = $this->requestWithBody(['name' => 'Blank Circuit', 'description' => 'desc', 'tags' => 'a,b']);

        $response = $this->controller->createBlank($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $circuits = $this->circuits->listVisible();
        $this->assertCount(1, $circuits);
        $this->assertSame('Blank Circuit', $circuits[0]['name']);
        $this->assertSame('/circuits/' . $circuits[0]['uuid'] . '/edit', $response->getHeaderLine('Location'));

        $storedFile = $this->storagePath . '/circuits/' . $circuits[0]['uuid'] . '/current.kml';
        $this->assertFileExists($storedFile);
    }

    public function testBlankCircuitRoundTripsAsEmptyFeatureCollection(): void
    {
        $createRequest = $this->requestWithBody(['name' => 'Blank Circuit']);
        $this->controller->createBlank($createRequest, (new ResponseFactory())->createResponse());

        $uuid = $this->circuits->listVisible()[0]['uuid'];

        $geoJsonResponse = $this->controller->geoJson(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/circuits/{$uuid}/geojson"),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $uuid]
        );

        $body = json_decode((string) $geoJsonResponse->getBody(), true);
        $this->assertSame(['type' => 'FeatureCollection', 'features' => []], $body);
    }

    public function testMissingNameIsRejected(): void
    {
        $request = $this->requestWithBody(['name' => '']);

        $response = $this->controller->createBlank($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->circuits->listVisible());
    }

    public function testProviderAndOrderFieldsRoundTrip(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', '1-800-555-0100', 'ACC-1', 'Jane Rep');

        $request = $this->requestWithBody([
            'name' => 'Provisioned Circuit',
            'provider_id' => (string) $providerId,
            'provider_circuit_id' => 'CKT-123',
            'order_number' => 'ORD-456',
            'redundant' => '1',
        ]);

        $response = $this->controller->createBlank($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $circuit = $this->circuits->listVisible()[0];
        $this->assertSame($providerId, (int) $circuit['provider_id']);
        $this->assertSame('CKT-123', $circuit['provider_circuit_id']);
        $this->assertSame('ORD-456', $circuit['order_number']);
        $this->assertSame(1, (int) $circuit['redundant']);
    }

    public function testInactiveProviderIsRejected(): void
    {
        $providerId = $this->providers->insert('Inactive Co', null, null, null);
        $this->providers->setActive($providerId, false);

        $request = $this->requestWithBody([
            'name' => 'Circuit With Bad Provider',
            'provider_id' => (string) $providerId,
        ]);

        $response = $this->controller->createBlank($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->circuits->listVisible());
    }
}
