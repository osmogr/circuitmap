<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Cacti;

use CircuitMap\Services\Cacti\CactiClient;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real queries against an in-memory SQLite database that
 * mimics the two Cacti tables the client reads (the SQL used is engine-
 * neutral), so the row mapping and bytes/sec -> bits/sec conversion are
 * covered without a MySQL server.
 */
final class CactiClientTest extends TestCase
{
    private function makeClient(PDO $pdo): CactiClient
    {
        return new CactiClient('unused', 3306, 'unused', 'unused', '', 'traffic_in', 'traffic_out', 5, $pdo);
    }

    private function makeCactiDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE host (id INTEGER PRIMARY KEY, status INTEGER, disabled TEXT DEFAULT \'\')');
        $pdo->exec(
            'CREATE TABLE data_source_stats_hourly_last (
                local_data_id INTEGER, rrd_name TEXT, calculated REAL,
                PRIMARY KEY (local_data_id, rrd_name)
            )'
        );
        return $pdo;
    }

    public function testGetHostStatusesMapsRowsAndNormalizesDisabledFlag(): void
    {
        $pdo = $this->makeCactiDb();
        $pdo->exec("INSERT INTO host (id, status, disabled) VALUES (10, 3, ''), (11, 1, 'on'), (12, 2, '')");

        $result = $this->makeClient($pdo)->getHostStatuses([10, 11, 99]);

        $this->assertSame(['status' => 3, 'disabled' => false], $result[10]);
        $this->assertSame(['status' => 1, 'disabled' => true], $result[11]);
        $this->assertArrayNotHasKey(12, $result, 'unrequested host must not be returned');
        $this->assertArrayNotHasKey(99, $result, 'missing host id must simply be absent');
    }

    public function testGetTrafficRatesConvertsBytesPerSecondToBits(): void
    {
        $pdo = $this->makeCactiDb();
        // 12,500,000 bytes/sec = 100,000,000 bits/sec (100 Mbps).
        $pdo->exec(
            "INSERT INTO data_source_stats_hourly_last VALUES
                (5, 'traffic_in', 12500000),
                (5, 'traffic_out', 1250000),
                (6, 'traffic_in', 100),
                (5, 'unrelated_field', 999999)"
        );

        $result = $this->makeClient($pdo)->getTrafficRates([5, 6, 77]);

        $this->assertSame(['in_bps' => 100000000, 'out_bps' => 10000000], $result[5]);
        $this->assertSame(['in_bps' => 800, 'out_bps' => null], $result[6], 'missing direction stays null');
        $this->assertArrayNotHasKey(77, $result, 'id with no DSStats rows must be absent');
    }

    public function testEmptyIdListsShortCircuitWithoutQuerying(): void
    {
        // No tables exist on this connection: any query would throw.
        $pdo = new PDO('sqlite::memory:');
        $client = $this->makeClient($pdo);

        $this->assertSame([], $client->getHostStatuses([]));
        $this->assertSame([], $client->getTrafficRates([]));
    }
}
