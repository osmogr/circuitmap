<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Controllers\EditController;
use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\CircuitVersionRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class EditFlowTest extends DatabaseTestCase
{
    private EditController $controller;
    private CircuitRepository $circuits;
    private CircuitProviderRepository $providers;
    private LocationRepository $locations;
    private CircuitVersionRepository $versions;
    private FileStorageService $storage;
    private int $ownerId;
    private int $otherUserId;
    private string $uuid;
    private int $circuitId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser('owner');
        $this->otherUserId = $this->createUser('other');
        $this->circuits = new CircuitRepository($this->pdo);
        $this->providers = new CircuitProviderRepository($this->pdo);
        $this->locations = new LocationRepository($this->pdo);
        $this->versions = new CircuitVersionRepository($this->pdo);
        $this->storage = new FileStorageService($this->storagePath);

        $auth = new AuthService(new UserRepository($this->pdo));
        $this->controller = new EditController(
            $this->pdo,
            $auth,
            new CsrfService(),
            $this->circuits,
            $this->providers,
            $this->locations,
            $this->versions,
            new AuditLogRepository($this->pdo),
            $this->storage,
            new KmlParser(),
            new KmlValidator(),
            new KmlSanitizer(),
            new GeoJsonConverter()
        );

        $this->uuid = Uuid::v4();
        $originalKml = (string) file_get_contents(dirname(__DIR__) . '/fixtures/valid_simple.kml');
        $this->storage->saveNew($this->uuid, $originalKml);
        $this->circuitId = $this->circuits->insert(
            $this->uuid,
            'Original Name',
            'Original description',
            null,
            $this->ownerId,
            $this->storage->relativePath($this->uuid)
        );
    }

    private function requestFor(int $userId, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        return (new ServerRequestFactory())->createServerRequest('PUT', '/circuits/' . $this->uuid)
            ->withParsedBody($body)
            ->withBody((new \Slim\Psr7\Factory\StreamFactory())->createStream(json_encode($body)))
            ->withAttribute('currentUser', $user);
    }

    public function testOwnerEditCreatesVersionSnapshotAndUpdatesCurrentFile(): void
    {
        $payload = [
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'tags' => null,
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame('Updated Name', $circuit['name']);
        $this->assertSame(2, (int) $circuit['current_version']);

        $versions = $this->versions->listForCircuit($this->circuitId);
        $this->assertCount(1, $versions);
        $this->assertSame(1, (int) $versions[0]['version_number']);
        $this->assertSame('Original Name', $versions[0]['name_snapshot']);

        $currentContent = $this->storage->read($this->uuid);
        $this->assertStringContainsString('Moved', $currentContent);

        $archivedContent = $this->storage->readVersion($this->uuid, 1);
        $this->assertStringContainsString('Segment A', $archivedContent);
    }

    public function testProviderAndOrderFieldsRoundTrip(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', '1-800-555-0100', 'ACC-1', 'Jane Rep');

        $payload = [
            'name' => 'Updated Name',
            'provider_id' => (string) $providerId,
            'provider_circuit_id' => 'CKT-123',
            'order_number' => 'ORD-456',
            'redundant' => '1',
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame($providerId, (int) $circuit['provider_id']);
        $this->assertSame('CKT-123', $circuit['provider_circuit_id']);
        $this->assertSame('ORD-456', $circuit['order_number']);
        $this->assertSame(1, (int) $circuit['redundant']);
    }

    public function testSavingWithoutChangingAnAlreadyDeactivatedProviderIsAllowed(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);
        $this->circuits->updateAfterEdit(
            $this->circuitId,
            'Original Name',
            'Original description',
            null,
            1,
            $providerId
        );
        $this->providers->setActive($providerId, false);

        $payload = [
            'name' => 'Original Name',
            'provider_id' => (string) $providerId,
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());
        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame($providerId, (int) $circuit['provider_id']);
    }

    public function testLocationFieldsRoundTrip(): void
    {
        $aLocationId = $this->locations->insert('Main St DC', null, null);
        $zLocationId = $this->locations->insert('Elm St POP', null, null);

        $payload = [
            'name' => 'Updated Name',
            'a_location_id' => (string) $aLocationId,
            'z_location_id' => (string) $zLocationId,
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame($aLocationId, (int) $circuit['a_location_id']);
        $this->assertSame($zLocationId, (int) $circuit['z_location_id']);
    }

    public function testCactiFieldsRoundTrip(): void
    {
        $payload = [
            'name' => 'Updated Name',
            'cacti_local_data_id' => 7,
            'capacity_bps' => 100000000,
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame(7, (int) $circuit['cacti_local_data_id']);
        $this->assertSame(100000000, (int) $circuit['capacity_bps']);

        // Saving again with the fields blank clears the mapping.
        $payload['cacti_local_data_id'] = null;
        $payload['capacity_bps'] = null;
        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(200, $response->getStatusCode());
        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertNull($circuit['cacti_local_data_id']);
        $this->assertNull($circuit['capacity_bps']);
    }

    public function testInvalidCactiFieldValuesAreRejected(): void
    {
        foreach (['-5', 'abc', '0', 3.7] as $bad) {
            $payload = [
                'name' => 'Updated Name',
                'cacti_local_data_id' => $bad,
                'geojson' => [
                    'type' => 'FeatureCollection',
                    'features' => [[
                        'type' => 'Feature',
                        'properties' => ['name' => 'Moved', 'description' => ''],
                        'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                    ]],
                ],
            ];

            $response = $this->controller->update(
                $this->requestFor($this->ownerId, $payload),
                (new ResponseFactory())->createResponse(),
                ['uuid' => $this->uuid]
            );

            $this->assertSame(422, $response->getStatusCode(), 'value should be rejected: ' . var_export($bad, true));
        }

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertNull($circuit['cacti_local_data_id']);
        $this->assertSame('Original Name', $circuit['name'], 'rejected edit must have no side effects');
    }

    public function testSwitchingToADeactivatedLocationIsRejected(): void
    {
        $locationId = $this->locations->insert('Main St DC', null, null);
        $this->locations->setActive($locationId, false);

        $payload = [
            'name' => 'Original Name',
            'a_location_id' => (string) $locationId,
            'geojson' => ['type' => 'FeatureCollection', 'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Moved', 'description' => ''],
                'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
            ]]],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(422, $response->getStatusCode());
        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertNull($circuit['a_location_id']);
    }

    public function testSwitchingToADeactivatedProviderIsRejected(): void
    {
        $providerId = $this->providers->insert('Acme Telecom', null, null, null);
        $this->providers->setActive($providerId, false);

        $payload = [
            'name' => 'Original Name',
            'provider_id' => (string) $providerId,
            'geojson' => ['type' => 'FeatureCollection', 'features' => [[
                'type' => 'Feature',
                'properties' => ['name' => 'Moved', 'description' => ''],
                'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
            ]]],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->ownerId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(422, $response->getStatusCode());
        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertNull($circuit['provider_id']);
    }

    public function testNonOwnerEditIsRejectedWithNoSideEffects(): void
    {
        $payload = [
            'name' => 'Hijacked',
            'geojson' => ['type' => 'FeatureCollection', 'features' => []],
        ];

        $response = $this->controller->update(
            $this->requestFor($this->otherUserId, $payload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $this->assertSame(403, $response->getStatusCode());

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame('Original Name', $circuit['name']);
        $this->assertSame(1, (int) $circuit['current_version']);
        $this->assertCount(0, $this->versions->listForCircuit($this->circuitId));
    }

    public function testRevertRestoresPriorContentAndArchivesWhatItReplaces(): void
    {
        $editPayload = [
            'name' => 'Updated Name',
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'properties' => ['name' => 'Moved', 'description' => ''],
                    'geometry' => ['type' => 'Point', 'coordinates' => [1.0, 2.0]],
                ]],
            ],
        ];
        $this->controller->update(
            $this->requestFor($this->ownerId, $editPayload),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid]
        );

        $revertResponse = $this->controller->revert(
            $this->requestFor($this->ownerId, []),
            (new ResponseFactory())->createResponse(),
            ['uuid' => $this->uuid, 'version' => '1']
        );

        $this->assertSame(200, $revertResponse->getStatusCode());

        $currentContent = $this->storage->read($this->uuid);
        $this->assertStringContainsString('Segment A', $currentContent);
        $this->assertStringNotContainsString('Moved', $currentContent);

        $circuit = $this->circuits->findByUuid($this->uuid);
        $this->assertSame(3, (int) $circuit['current_version']);

        // The edited (v2) content that revert replaced must itself be
        // archived, so the chain of edits stays fully recoverable.
        $archivedV2 = $this->storage->readVersion($this->uuid, 2);
        $this->assertStringContainsString('Moved', $archivedV2);
    }
}
