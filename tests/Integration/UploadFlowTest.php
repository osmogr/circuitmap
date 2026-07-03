<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Models\UserRepository;
use CircuitMap\Controllers\CircuitController;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

/**
 * Exercises CircuitController::upload() directly (real DB, real file
 * storage, real KML parsing/validation/sanitization pipeline) rather than
 * going through the full HTTP stack. Auth/CSRF gating is covered
 * separately by manual verification against the running container; this
 * test is scoped to the upload business logic itself, matching the
 * project's phased build order (upload -> store -> display first).
 */
final class UploadFlowTest extends DatabaseTestCase
{
    private CircuitController $controller;
    private CircuitRepository $circuits;
    private CircuitProviderRepository $providers;
    private LocationRepository $locations;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);
        $this->providers = new CircuitProviderRepository($this->pdo);
        $this->locations = new LocationRepository($this->pdo);
        $auth = new AuthService(new UserRepository($this->pdo));

        $this->controller = new CircuitController(
            $auth,
            new CsrfService(),
            $this->circuits,
            $this->providers,
            $this->locations,
            new AuditLogRepository($this->pdo),
            new FileStorageService($this->storagePath),
            new KmlParser(),
            new KmlValidator(),
            new KmlSanitizer(),
            new GeoJsonConverter(),
            new KmzExtractor()
        );
    }

    private function fixture(string $name): string
    {
        return dirname(__DIR__) . '/fixtures/' . $name;
    }

    private function uploadedFileFrom(string $fixturePath): UploadedFile
    {
        return new UploadedFile($fixturePath, basename($fixturePath), null, filesize($fixturePath), UPLOAD_ERR_OK);
    }

    private function requestWithUpload(array $formFields, UploadedFile $file)
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/upload')
            ->withParsedBody($formFields)
            ->withUploadedFiles(['kml_file' => $file])
            ->withAttribute('currentUser', ['id' => $this->userId, 'username' => 'testuser', 'role' => 'editor']);

        return $request;
    }

    public function testValidUploadCreatesFileAndDatabaseRow(): void
    {
        $request = $this->requestWithUpload(
            ['name' => 'Test Circuit', 'description' => 'desc', 'tags' => 'a,b'],
            $this->uploadedFileFrom($this->fixture('valid_simple.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $circuits = $this->circuits->listVisible();
        $this->assertCount(1, $circuits);
        $this->assertSame('Test Circuit', $circuits[0]['name']);

        $storedFile = $this->storagePath . '/circuits/' . $circuits[0]['uuid'] . '/current.kml';
        $this->assertFileExists($storedFile);
    }

    public function testInvalidKmlIsRejectedWithNoPartialWrite(): void
    {
        $request = $this->requestWithUpload(
            ['name' => 'Bad Circuit'],
            $this->uploadedFileFrom($this->fixture('invalid_malformed.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->circuits->listVisible());
        $this->assertDirectoryDoesNotExist($this->storagePath . '/circuits');
    }

    public function testXxeAttemptIsRejectedWithNoPartialWrite(): void
    {
        $request = $this->requestWithUpload(
            ['name' => 'Xxe Circuit'],
            $this->uploadedFileFrom($this->fixture('xxe_attempt.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->circuits->listVisible());
    }

    public function testMissingNameIsRejected(): void
    {
        $request = $this->requestWithUpload(
            ['name' => ''],
            $this->uploadedFileFrom($this->fixture('valid_simple.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(0, $this->circuits->listVisible());
    }

    public function testProviderAndOrderFieldsRoundTrip(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);

        $request = $this->requestWithUpload(
            [
                'name' => 'Provisioned Circuit',
                'provider_id' => (string) $providerId,
                'provider_circuit_id' => 'CKT-123',
                'order_number' => 'ORD-456',
                'redundant' => '1',
            ],
            $this->uploadedFileFrom($this->fixture('valid_simple.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $circuit = $this->circuits->listVisible()[0];
        $this->assertSame($providerId, (int) $circuit['provider_id']);
        $this->assertSame('CKT-123', $circuit['provider_circuit_id']);
        $this->assertSame('ORD-456', $circuit['order_number']);
        $this->assertSame(1, (int) $circuit['redundant']);
    }

    public function testLocationFieldsRoundTrip(): void
    {
        $aLocationId = $this->locations->insert('Main St DC', null, null);
        $zLocationId = $this->locations->insert('Elm St POP', null, null);

        $request = $this->requestWithUpload(
            [
                'name' => 'Circuit With Locations',
                'a_location_id' => (string) $aLocationId,
                'z_location_id' => (string) $zLocationId,
            ],
            $this->uploadedFileFrom($this->fixture('valid_simple.kml'))
        );

        $response = $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $circuit = $this->circuits->listVisible()[0];
        $this->assertSame($aLocationId, (int) $circuit['a_location_id']);
        $this->assertSame($zLocationId, (int) $circuit['z_location_id']);
    }

    public function testDescriptionIsSanitizedInStoredFile(): void
    {
        $request = $this->requestWithUpload(
            ['name' => 'XSS Test'],
            $this->uploadedFileFrom($this->fixture('valid_simple.kml'))
        );

        $this->controller->upload($request, (new ResponseFactory())->createResponse());

        $circuits = $this->circuits->listVisible();
        $storedFile = $this->storagePath . '/circuits/' . $circuits[0]['uuid'] . '/current.kml';
        $contents = (string) file_get_contents($storedFile);

        $this->assertStringNotContainsString('<script', $contents);
    }
}
