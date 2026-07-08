<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

use PDO;

/**
 * Reads host state and DSStats traffic rates straight from a Cacti 1.2.x
 * MySQL database with a read-only account. The PDO connection is opened
 * lazily on first query so constructing this in App bootstrap (or a poller
 * that has nothing mapped yet) costs nothing and cannot fail.
 *
 * Traffic comes from data_source_stats_hourly_last, which Cacti only
 * populates when DSStats is enabled; host status works either way.
 */
final class CactiClient implements CactiClientInterface
{
    private ?PDO $pdo;

    /**
     * $connection is a test seam: passing a pre-built PDO (e.g. SQLite with
     * a mimicked Cacti schema) exercises the real queries and unit
     * conversion without a MySQL server. Production always passes null.
     */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly string $trafficInName = 'traffic_in',
        private readonly string $trafficOutName = 'traffic_out',
        private readonly int $connectTimeoutSeconds = 5,
        ?PDO $connection = null
    ) {
        $this->pdo = $connection;
    }

    public function getHostStatuses(array $hostIds): array
    {
        $hostIds = array_values(array_unique(array_map('intval', $hostIds)));
        if ($hostIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($hostIds), '?'));
        $rows = $this->query(
            "SELECT id, status, disabled FROM host WHERE id IN ({$placeholders})",
            $hostIds
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = [
                'status' => (int) $row['status'],
                // Cacti stores disabled as 'on' / '' rather than a boolean.
                'disabled' => ($row['disabled'] ?? '') === 'on',
            ];
        }
        return $result;
    }

    public function getTrafficRates(array $localDataIds): array
    {
        $localDataIds = array_values(array_unique(array_map('intval', $localDataIds)));
        if ($localDataIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($localDataIds), '?'));
        $rows = $this->query(
            "SELECT local_data_id, rrd_name, calculated
             FROM data_source_stats_hourly_last
             WHERE local_data_id IN ({$placeholders}) AND rrd_name IN (?, ?)",
            [...$localDataIds, $this->trafficInName, $this->trafficOutName]
        );

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['local_data_id'];
            if (!isset($result[$id])) {
                $result[$id] = ['in_bps' => null, 'out_bps' => null];
            }
            // DSStats stores bytes/sec; circuits are described in bits/sec.
            $bps = $row['calculated'] === null ? null : (int) round((float) $row['calculated'] * 8);
            if ($row['rrd_name'] === $this->trafficInName) {
                $result[$id]['in_bps'] = $bps;
            } else {
                $result[$id]['out_bps'] = $bps;
            }
        }
        return $result;
    }

    /**
     * @param array<int, int|string> $params
     * @return array<int, array<string, mixed>>
     */
    private function query(string $sql, array $params): array
    {
        try {
            $stmt = $this->connection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // A failed query may mean the connection died; drop it so the
            // next poll cycle reconnects instead of reusing a dead handle.
            $this->pdo = null;
            throw new CactiUnavailableException('Cacti query failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function connection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database);
            try {
                $this->pdo = new PDO($dsn, $this->username, $this->password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => $this->connectTimeoutSeconds,
                ]);
            } catch (\PDOException $e) {
                throw new CactiUnavailableException('Cannot connect to Cacti database: ' . $e->getMessage(), 0, $e);
            }
        }
        return $this->pdo;
    }
}
