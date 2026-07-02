<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\StatusController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Status\ManualStatusProvider;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class StatusFlowTest extends DatabaseTestCase
{
    private StatusController $controller;
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

        $this->controller = new StatusController(
            $this->circuits,
            new AuditLogRepository($this->pdo),
            new ManualStatusProvider($this->circuits)
        );

        $storage = new FileStorageService($this->storagePath);
        $this->uuid = Uuid::v4();
        $storage->saveNew($this->uuid, '<kml/>');
        $this->circuits->insert($this->uuid, 'Circuit', null, null, $this->ownerId, $storage->relativePath($this->uuid));
    }

    private function requestFor(int $userId, array $formBody): \Psr\Http\Message\ServerRequestInterface
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        return (new ServerRequestFactory())->createServerRequest('POST', '/circuits/' . $this->uuid . '/status')
            ->withParsedBody($formBody)
            ->withAttribute('currentUser', $user);
    }

    public function testOwnerCanSetValidStatus(): void
    {
        $response = $this->controller->setStatus(
            $this->requestFor($this->ownerId, ['status' => 'up']),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame('up', $circuit['status']);
        $this->assertSame('manual', $circuit['status_source']);
        $this->assertNotNull($circuit['status_updated_at']);
    }

    public function testInvalidStatusValueIsRejected(): void
    {
        $response = $this->controller->setStatus(
            $this->requestFor($this->ownerId, ['status' => 'on_fire']),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('unknown', $this->circuits->findByUuid($this->uuid)['status']);
    }

    public function testNonOwnerCannotSetStatus(): void
    {
        $response = $this->controller->setStatus(
            $this->requestFor($this->otherUserId, ['status' => 'down']),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('unknown', $this->circuits->findByUuid($this->uuid)['status']);
    }

    public function testGetStatusReflectsManuallySetValueThroughProviderInterface(): void
    {
        $this->controller->setStatus(
            $this->requestFor($this->ownerId, ['status' => 'degraded']),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $response = $this->controller->getStatus(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/circuits/' . $this->uuid . '/status'),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('degraded', $body['status']);
        $this->assertSame('manual', $body['source']);
    }
}
