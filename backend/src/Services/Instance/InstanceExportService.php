<?php

declare(strict_types=1);

namespace CircuitMap\Services\Instance;

use CircuitMap\Services\Storage\FileStorageService;
use ZipArchive;

/**
 * Builds a full-instance archive: every persistent table as raw JSON rows
 * plus the complete per-circuit KML tree. Tables are dumped over raw PDO
 * rather than through the repositories on purpose — repositories apply
 * business defaults (fresh timestamps, forced initial versions) that must
 * never touch a byte-exact copy. Transient state (sessions, rate_limit_hits,
 * schema_migrations, pending imports) is deliberately excluded.
 */
final class InstanceExportService
{
    public const FORMAT = 'circuitmap-instance-export';
    public const FORMAT_VERSION = 1;

    /** Insert-safe (FK dependency) order; import replays it verbatim. */
    public const TABLES = [
        'users',
        'circuit_providers',
        'locations',
        'circuits',
        'circuit_versions',
        'audit_log',
    ];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly FileStorageService $storage
    ) {
    }

    /**
     * @return array{path: string, counts: array<string, int>}
     *   path is a temporary zip file the caller must unlink.
     */
    public function buildArchive(): array
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'circuitmap_instance_');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the instance export.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempPath, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the instance export archive.');
            }

            $counts = [];
            $tables = [];
            foreach (self::TABLES as $table) {
                $rows = $this->pdo->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
                $tables[$table] = $rows;
                $counts[$table] = count($rows);
                $zip->addFromString(
                    "data/{$table}.json",
                    json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)
                );
            }

            $this->addKmlFiles($zip, $tables['circuits'], $tables['circuit_versions']);

            $manifest = [
                'format' => self::FORMAT,
                'format_version' => self::FORMAT_VERSION,
                'created_at' => gmdate('c'),
                'migrations' => $this->appliedMigrations(),
                'counts' => $counts,
                'sequences' => $this->sequences(),
            ];
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));

            $zip->close();
        } catch (\Throwable $e) {
            @unlink($tempPath);
            throw $e;
        }

        return ['path' => $tempPath, 'counts' => $counts];
    }

    /**
     * @param array<int, array<string, mixed>> $circuits
     * @param array<int, array<string, mixed>> $versions
     */
    private function addKmlFiles(ZipArchive $zip, array $circuits, array $versions): void
    {
        $uuidsById = [];
        foreach ($circuits as $circuit) {
            $uuid = (string) $circuit['uuid'];
            $uuidsById[(int) $circuit['id']] = $uuid;
            // Soft-deleted circuits keep their files, so they export too.
            // A missing file means storage corruption; failing loudly beats
            // producing an archive that can never be imported.
            $zip->addFromString("kml/circuits/{$uuid}/current.kml", $this->storage->read($uuid));
        }

        foreach ($versions as $version) {
            $uuid = $uuidsById[(int) $version['circuit_id']] ?? null;
            if ($uuid === null) {
                throw new \RuntimeException(
                    'Circuit version ' . (int) $version['id'] . ' references a circuit that does not exist.'
                );
            }
            $number = (int) $version['version_number'];
            $zip->addFromString(
                "kml/circuits/{$uuid}/versions/v{$number}.kml",
                $this->storage->readVersion($uuid, $number)
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function appliedMigrations(): array
    {
        return $this->pdo->query('SELECT filename FROM schema_migrations ORDER BY filename')
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * @return array<string, int>
     */
    private function sequences(): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::TABLES), '?'));
        $stmt = $this->pdo->prepare("SELECT name, seq FROM sqlite_sequence WHERE name IN ({$placeholders})");
        $stmt->execute(self::TABLES);

        $sequences = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $sequences[(string) $row['name']] = (int) $row['seq'];
        }
        return $sequences;
    }
}
