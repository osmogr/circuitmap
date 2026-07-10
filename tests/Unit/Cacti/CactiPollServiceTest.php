<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Cacti;

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Cacti\CactiPollService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use CircuitMap\Tests\Support\FakeCactiClient;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CactiPollServiceTest extends DatabaseTestCase
{
    private CircuitRepository $circuits;
    private LocationRepository $locations;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);
        $this->locations = new LocationRepository($this->pdo);
    }

    private function poll(FakeCactiClient $client, int $staleAfterSeconds = 900): array
    {
        return (new CactiPollService($this->locations, $this->circuits, $client, $staleAfterSeconds))->poll();
    }

    private function createCircuit(
        string $name,
        ?int $cactiLocalDataId = null,
        ?int $capacityBps = null
    ): int {
        $id = $this->circuits->insert(Uuid::v4(), $name, null, null, $this->ownerId, 'circuits/x/current.kml');
        $stmt = $this->pdo->prepare(
            'UPDATE circuits SET cacti_local_data_id = :data, capacity_bps = :capacity WHERE id = :id'
        );
        $stmt->execute(['data' => $cactiLocalDataId, 'capacity' => $capacityBps, 'id' => $id]);
        return $id;
    }

    private function createLocation(string $name, ?int $cactiHostId = null): int
    {
        return $this->locations->insert($name, null, null, null, null, null, $cactiHostId);
    }

    /**
     * @return array<string, mixed>
     */
    private function circuitById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM circuits WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    /**
     * @return array<string, mixed>
     */
    private function locationById(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM locations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch();
    }

    public function testMappedLocationGetsStatusFromCactiWithoutBumpingUpdatedAt(): void
    {
        $id = $this->createLocation('Mapped site', 42);
        $this->pdo->exec("UPDATE locations SET updated_at = '2020-01-01T00:00:00Z' WHERE id = {$id}");

        $client = new FakeCactiClient([42 => ['status' => 1, 'disabled' => false]]);
        $result = $this->poll($client);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['statuses']);
        $location = $this->locationById($id);
        $this->assertSame('down', $location['status']);
        $this->assertNotNull($location['status_updated_at']);
        $this->assertSame('2020-01-01T00:00:00Z', $location['updated_at'], 'poller must not churn updated_at');
    }

    public function testNothingMappedMeansCactiIsNotQueried(): void
    {
        $locationId = $this->createLocation('Unmapped site');
        $this->createCircuit('Unmapped circuit');

        $client = new FakeCactiClient([42 => ['status' => 1, 'disabled' => false]]);
        $result = $this->poll($client);

        $this->assertSame(0, $result['locations'], 'nothing mapped means nothing polled');
        $this->assertSame(0, $result['circuits']);
        $this->assertSame(0, $client->hostStatusCalls, 'no mapped locations: Cacti must not even be queried');
        $this->assertSame(0, $client->trafficRateCalls);
        $this->assertSame('unknown', $this->locationById($locationId)['status']);
    }

    public function testManualCircuitStatusIsNeverTouchedByPoller(): void
    {
        $this->createLocation('Mapped site', 42);
        $circuitId = $this->createCircuit('Traffic circuit', 7);
        $this->circuits->updateStatus($circuitId, 'down', 'manual');

        $client = new FakeCactiClient(
            [42 => ['status' => 3, 'disabled' => false]],
            [7 => ['in_bps' => 1, 'out_bps' => 2]]
        );
        $this->poll($client);

        $circuit = $this->circuitById($circuitId);
        $this->assertSame('down', $circuit['status'], 'circuit status is manual-only');
        $this->assertSame('manual', $circuit['status_source']);
    }

    public function testHostIdMissingFromCactiYieldsUnknown(): void
    {
        $id = $this->createLocation('Mapped to deleted device', 999);
        $this->locations->updateStatusFromPoller($id, 'up');

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]]);
        $this->poll($client);

        $this->assertSame('unknown', $this->locationById($id)['status']);
    }

    public function testTrafficRatesAreStoredForMappedDataSource(): void
    {
        $id = $this->createCircuit('With traffic', 7, 100_000_000);

        $client = new FakeCactiClient(
            [],
            [7 => ['in_bps' => 25_000_000, 'out_bps' => 50_000_000]]
        );
        $result = $this->poll($client);

        $this->assertSame(1, $result['usages']);
        $this->assertSame(0, $client->hostStatusCalls, 'no mapped locations: host statuses must not be queried');
        $circuit = $this->circuitById($id);
        $this->assertSame(25_000_000, (int) $circuit['usage_in_bps']);
        $this->assertSame(50_000_000, (int) $circuit['usage_out_bps']);
        $this->assertNotNull($circuit['usage_updated_at']);
    }

    public function testMissingDsstatsRowClearsStoredUsage(): void
    {
        $id = $this->createCircuit('DSStats gone', 7);
        $this->circuits->updateUsage($id, 123, 456);

        $client = new FakeCactiClient([], []);
        $this->poll($client);

        $circuit = $this->circuitById($id);
        $this->assertNull($circuit['usage_in_bps'], 'stale numbers must not be shown as current');
        $this->assertNull($circuit['usage_out_bps']);
    }

    public function testLocationOnlyMappingSkipsTrafficEntirely(): void
    {
        $this->createLocation('Status only site', 42);

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]]);
        $result = $this->poll($client);

        $this->assertSame(0, $result['usages']);
        $this->assertSame(0, $client->trafficRateCalls);
    }

    public function testUnavailableCactiOnlyFlipsLocationStatusesPastTheStaleCutoff(): void
    {
        $freshId = $this->createLocation('Fresh', 42);
        $staleId = $this->createLocation('Stale', 43);
        $manualCircuitId = $this->createCircuit('Manual circuit', 7);

        $this->locations->updateStatusFromPoller($freshId, 'up');
        $this->locations->updateStatusFromPoller($staleId, 'up');
        $this->pdo->exec("UPDATE locations SET status_updated_at = '2020-01-01T00:00:00Z' WHERE id = {$staleId}");
        $this->circuits->updateStatus($manualCircuitId, 'down', 'manual');
        $this->pdo->exec("UPDATE circuits SET status_updated_at = '2020-01-01T00:00:00Z' WHERE id = {$manualCircuitId}");

        $client = new FakeCactiClient();
        $client->unavailable = true;
        $result = $this->poll($client);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['stale']);
        $this->assertSame('up', $this->locationById($freshId)['status'], 'recently-polled status survives one miss');
        $this->assertSame('unknown', $this->locationById($staleId)['status'], 'stale status flips to unknown');
        $this->assertSame('down', $this->circuitById($manualCircuitId)['status'], 'circuit statuses are never staled');
    }

    public function testCircuitsListJsonExposesUsageAndUtilization(): void
    {
        $this->createCircuit('Utilized', 7, 100_000_000);

        $client = new FakeCactiClient(
            [],
            [7 => ['in_bps' => 25_000_000, 'out_bps' => 50_000_000]]
        );
        $this->poll($client);

        $response = $this->makeCircuitController()->listJson(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/circuits'),
            (new ResponseFactory())->createResponse()
        );
        $payload = json_decode((string) $response->getBody(), true);
        $circuit = $payload['circuits'][0];

        $this->assertSame(25_000_000, (int) $circuit['usage_in_bps']);
        $this->assertSame(50_000_000, (int) $circuit['usage_out_bps']);
        $this->assertSame(100_000_000, (int) $circuit['capacity_bps']);
        $this->assertSame(50.0, (float) $circuit['utilizationPct'], 'busier direction / capacity');
        $this->assertArrayHasKey('statusColor', $circuit);
    }
}
