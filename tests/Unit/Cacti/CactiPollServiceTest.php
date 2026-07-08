<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Cacti;

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Cacti\CactiPollService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use CircuitMap\Tests\Support\FakeCactiClient;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CactiPollServiceTest extends DatabaseTestCase
{
    private CircuitRepository $circuits;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownerId = $this->createUser();
        $this->circuits = new CircuitRepository($this->pdo);
    }

    private function createCircuit(
        string $name,
        ?int $cactiHostId = null,
        ?int $cactiLocalDataId = null,
        ?int $capacityBps = null
    ): int {
        $id = $this->circuits->insert(Uuid::v4(), $name, null, null, $this->ownerId, 'circuits/x/current.kml');
        $stmt = $this->pdo->prepare(
            'UPDATE circuits
             SET cacti_host_id = :host, cacti_local_data_id = :data, capacity_bps = :capacity
             WHERE id = :id'
        );
        $stmt->execute(['host' => $cactiHostId, 'data' => $cactiLocalDataId, 'capacity' => $capacityBps, 'id' => $id]);
        return $id;
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

    public function testMappedCircuitGetsStatusFromCactiWithoutBumpingUpdatedAt(): void
    {
        $id = $this->createCircuit('Mapped', 42);
        $this->pdo->exec("UPDATE circuits SET updated_at = '2020-01-01T00:00:00Z' WHERE id = {$id}");

        $client = new FakeCactiClient([42 => ['status' => 1, 'disabled' => false]]);
        $result = (new CactiPollService($this->circuits, $client))->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['statuses']);
        $circuit = $this->circuitById($id);
        $this->assertSame('down', $circuit['status']);
        $this->assertSame('cacti', $circuit['status_source']);
        $this->assertNotNull($circuit['status_updated_at']);
        $this->assertSame('2020-01-01T00:00:00Z', $circuit['updated_at'], 'poller must not churn updated_at');
    }

    public function testUnmappedCircuitIsUntouched(): void
    {
        $id = $this->createCircuit('Unmapped');

        $client = new FakeCactiClient([42 => ['status' => 1, 'disabled' => false]]);
        $result = (new CactiPollService($this->circuits, $client))->poll();

        $this->assertSame(0, $result['circuits'], 'nothing mapped means nothing polled');
        $this->assertSame(0, $client->hostStatusCalls, 'no mapped circuits: Cacti must not even be queried');
        $circuit = $this->circuitById($id);
        $this->assertSame('unknown', $circuit['status']);
        $this->assertNull($circuit['status_source']);
    }

    public function testManualStatusIsOverwrittenForMappedCircuit(): void
    {
        $id = $this->createCircuit('Mapped', 42);
        $this->circuits->updateStatus($id, 'down', 'manual');

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]]);
        (new CactiPollService($this->circuits, $client))->poll();

        $circuit = $this->circuitById($id);
        $this->assertSame('up', $circuit['status']);
        $this->assertSame('cacti', $circuit['status_source']);
    }

    public function testHostIdMissingFromCactiYieldsUnknown(): void
    {
        $id = $this->createCircuit('Mapped to deleted device', 999);

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]]);
        (new CactiPollService($this->circuits, $client))->poll();

        $this->assertSame('unknown', $this->circuitById($id)['status']);
    }

    public function testTrafficRatesAreStoredForMappedDataSource(): void
    {
        $id = $this->createCircuit('With traffic', 42, 7, 100_000_000);

        $client = new FakeCactiClient(
            [42 => ['status' => 3, 'disabled' => false]],
            [7 => ['in_bps' => 25_000_000, 'out_bps' => 50_000_000]]
        );
        $result = (new CactiPollService($this->circuits, $client))->poll();

        $this->assertSame(1, $result['usages']);
        $circuit = $this->circuitById($id);
        $this->assertSame(25_000_000, (int) $circuit['usage_in_bps']);
        $this->assertSame(50_000_000, (int) $circuit['usage_out_bps']);
        $this->assertNotNull($circuit['usage_updated_at']);
    }

    public function testMissingDsstatsRowClearsStoredUsage(): void
    {
        $id = $this->createCircuit('DSStats gone', 42, 7);
        $this->circuits->updateUsage($id, 123, 456);

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]], []);
        (new CactiPollService($this->circuits, $client))->poll();

        $circuit = $this->circuitById($id);
        $this->assertNull($circuit['usage_in_bps'], 'stale numbers must not be shown as current');
        $this->assertNull($circuit['usage_out_bps']);
    }

    public function testCircuitWithoutDataSourceMappingSkipsTrafficEntirely(): void
    {
        $id = $this->createCircuit('Status only', 42);

        $client = new FakeCactiClient([42 => ['status' => 3, 'disabled' => false]]);
        $result = (new CactiPollService($this->circuits, $client))->poll();

        $this->assertSame(0, $result['usages']);
        $this->assertSame(0, $client->trafficRateCalls);
        $this->assertNull($this->circuitById($id)['usage_updated_at']);
    }

    public function testUnavailableCactiOnlyFlipsStatusesPastTheStaleCutoff(): void
    {
        $freshId = $this->createCircuit('Fresh', 42);
        $staleId = $this->createCircuit('Stale', 43);
        $manualId = $this->createCircuit('Manual, stale timestamp', 44);

        $this->circuits->updateStatusFromPoller($freshId, 'up');
        $this->circuits->updateStatusFromPoller($staleId, 'up');
        $this->pdo->exec("UPDATE circuits SET status_updated_at = '2020-01-01T00:00:00Z' WHERE id = {$staleId}");
        $this->circuits->updateStatus($manualId, 'down', 'manual');
        $this->pdo->exec("UPDATE circuits SET status_updated_at = '2020-01-01T00:00:00Z' WHERE id = {$manualId}");

        $client = new FakeCactiClient();
        $client->unavailable = true;
        $result = (new CactiPollService($this->circuits, $client, 900))->poll();

        $this->assertFalse($result['ok']);
        $this->assertSame(1, $result['stale']);
        $this->assertSame('up', $this->circuitById($freshId)['status'], 'recently-polled status survives one miss');
        $this->assertSame('unknown', $this->circuitById($staleId)['status'], 'stale cacti status flips to unknown');
        $this->assertSame('down', $this->circuitById($manualId)['status'], 'manual statuses are never staled');
    }

    public function testCircuitsListJsonExposesUsageAndUtilization(): void
    {
        $this->createCircuit('Utilized', 42, 7, 100_000_000);

        $client = new FakeCactiClient(
            [42 => ['status' => 3, 'disabled' => false]],
            [7 => ['in_bps' => 25_000_000, 'out_bps' => 50_000_000]]
        );
        (new CactiPollService($this->circuits, $client))->poll();

        $response = $this->makeCircuitController()->listJson(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/circuits'),
            (new ResponseFactory())->createResponse()
        );
        $payload = json_decode((string) $response->getBody(), true);
        $circuit = $payload['circuits'][0];

        $this->assertSame('up', $circuit['status']);
        $this->assertSame(25_000_000, (int) $circuit['usage_in_bps']);
        $this->assertSame(50_000_000, (int) $circuit['usage_out_bps']);
        $this->assertSame(100_000_000, (int) $circuit['capacity_bps']);
        $this->assertSame(50.0, (float) $circuit['utilizationPct'], 'busier direction / capacity');
        $this->assertArrayHasKey('statusColor', $circuit);
    }
}
