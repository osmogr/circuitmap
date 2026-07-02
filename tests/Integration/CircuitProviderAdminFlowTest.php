<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\CircuitProviderController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises CircuitProviderController directly (real DB), same scope as the
 * other flow tests: business logic against a real database, not the full
 * HTTP/auth/CSRF/role-middleware stack.
 */
final class CircuitProviderAdminFlowTest extends DatabaseTestCase
{
    private CircuitProviderController $controller;
    private CircuitProviderRepository $providers;
    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = $this->createUser('admin', 'admin');
        $this->providers = new CircuitProviderRepository($this->pdo);

        $this->controller = new CircuitProviderController(
            $this->providers,
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

    public function testCreateProviderPersistsAllFields(): void
    {
        $request = $this->requestWithBody('POST', '/admin/providers', [
            'name' => 'Acme Telecom',
            'tech_support_number' => '1-800-555-0100',
            'account_id' => 'ACC-1',
            'local_rep_contact' => 'Jane Rep <jane@example.com>',
        ]);

        $response = $this->controller->createProvider($request, (new ResponseFactory())->createResponse());

        $this->assertSame(302, $response->getStatusCode());

        $providers = $this->providers->listAll();
        $this->assertCount(1, $providers);
        $this->assertSame('Acme Telecom', $providers[0]['name']);
        $this->assertSame('1-800-555-0100', $providers[0]['tech_support_number']);
        $this->assertSame('ACC-1', $providers[0]['account_id']);
        $this->assertSame('Jane Rep <jane@example.com>', $providers[0]['local_rep_contact']);
        $this->assertSame(1, (int) $providers[0]['is_active']);
    }

    public function testDuplicateNameIsRejected(): void
    {
        $this->providers->insert('Acme Telecom', null, null, null);

        $request = $this->requestWithBody('POST', '/admin/providers', ['name' => 'Acme Telecom']);
        $response = $this->controller->createProvider($request, (new ResponseFactory())->createResponse());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertCount(1, $this->providers->listAll());
    }

    public function testUpdateProviderChangesFields(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);

        $request = $this->requestWithBody('POST', "/admin/providers/{$providerId}", [
            'name' => 'Acme Telecom Inc',
            'tech_support_number' => '1-800-555-0199',
            'account_id' => 'ACC-2',
            'local_rep_contact' => 'John Rep',
        ]);

        $response = $this->controller->updateProvider(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $providerId]
        );

        $this->assertSame(302, $response->getStatusCode());

        $provider = $this->providers->findById($providerId);
        $this->assertSame('Acme Telecom Inc', $provider['name']);
        $this->assertSame('1-800-555-0199', $provider['tech_support_number']);
        $this->assertSame('ACC-2', $provider['account_id']);
        $this->assertSame('John Rep', $provider['local_rep_contact']);
    }

    public function testDeactivateRemovesProviderFromActiveListButKeepsExistingReference(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);

        $circuits = new CircuitRepository($this->pdo);
        $storage = new FileStorageService($this->storagePath);
        $uuid = Uuid::v4();
        $storage->saveNew($uuid, '<kml/>');
        $circuits->insert(
            $uuid,
            'Circuit using provider',
            null,
            null,
            $this->adminId,
            $storage->relativePath($uuid),
            $providerId
        );

        $request = $this->requestWithBody('POST', "/admin/providers/{$providerId}/active", ['active' => '0']);
        $response = $this->controller->setActive(
            $request,
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $providerId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(0, $this->providers->listActive());
        $this->assertCount(1, $this->providers->listAll());

        $circuit = $circuits->findByUuid($uuid);
        $this->assertSame($providerId, (int) $circuit['provider_id']);
    }
}
