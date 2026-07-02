<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\CircuitController;
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
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DeleteFlowTest extends DatabaseTestCase
{
    private CircuitController $controller;
    private CircuitRepository $circuits;
    private int $ownerId;
    private int $otherUserId;
    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser('owner');
        $this->otherUserId = $this->createUser('other');
        $this->circuits = new CircuitRepository($this->pdo);
        $auth = new AuthService(new UserRepository($this->pdo));

        $this->controller = new CircuitController(
            $auth,
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

        $storage = new FileStorageService($this->storagePath);
        $this->uuid = Uuid::v4();
        $storage->saveNew($this->uuid, '<kml/>');
        $this->circuits->insert($this->uuid, 'Circuit', null, null, $this->ownerId, $storage->relativePath($this->uuid));
    }

    private function requestFor(int $userId): \Psr\Http\Message\ServerRequestInterface
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        return (new ServerRequestFactory())->createServerRequest('DELETE', '/circuits/' . $this->uuid)
            ->withAttribute('currentUser', $user);
    }

    public function testOwnerCanDeleteCircuit(): void
    {
        $response = $this->controller->delete(
            $this->requestFor($this->ownerId),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->circuits->findByUuid($this->uuid));
        $this->assertCount(0, $this->circuits->listVisible());
    }

    public function testNonOwnerCannotDeleteCircuit(): void
    {
        $response = $this->controller->delete(
            $this->requestFor($this->otherUserId),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->circuits->findByUuid($this->uuid));
    }
}
