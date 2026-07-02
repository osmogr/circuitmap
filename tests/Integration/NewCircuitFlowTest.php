<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Models\AuditLogRepository;
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
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);

        $this->controller = new CircuitController(
            new AuthService(new UserRepository($this->pdo)),
            new CsrfService(),
            $this->circuits,
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
}
