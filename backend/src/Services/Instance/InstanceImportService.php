<?php

declare(strict_types=1);

namespace CircuitMap\Services\Instance;

use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use ZipArchive;

/**
 * Restores an InstanceExportService archive into a fresh instance. Every
 * validation (zip safety, manifest/schema compatibility, row shape, FK
 * integrity, row/file cross-checks) runs before the first write; the
 * restore itself is all-or-nothing — one DB transaction whose commit only
 * happens after every KML file has landed, with written files cleaned up
 * on rollback. Refuses to run against a non-empty instance.
 */
final class InstanceImportService
{
    private const MAX_COMPRESSION_RATIO = 100;

    /** referencing table => [column => [referenced table, nullable]] */
    private const FOREIGN_KEYS = [
        'circuits' => [
            'owner_id' => ['users', false],
            'provider_id' => ['circuit_providers', true],
            'a_location_id' => ['locations', true],
            'z_location_id' => ['locations', true],
        ],
        'circuit_versions' => [
            'circuit_id' => ['circuits', false],
            'edited_by' => ['users', true],
        ],
        'audit_log' => [
            'user_id' => ['users', true],
            'circuit_id' => ['circuits', true],
        ],
    ];

    /** @var array<string, int> set by validateManifest, used by restore */
    private array $sequences = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly FileStorageService $storage,
        private readonly int $maxTotalUncompressedBytes = 1_073_741_824
    ) {
    }

    /**
     * @return array{counts: array<string, int>, rebindUser: array<string, mixed>|null}
     *   rebindUser is the imported active-admin row matching $currentUsername
     *   (by username, never by id — the same id on another instance is a
     *   different person), or null when the importing admin no longer exists.
     */
    public function import(string $zipPath, ?string $currentUsername): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CHECKCONS) !== true) {
            throw new InstanceImportException('The uploaded file is not a valid ZIP archive.');
        }

        try {
            $kmlEntries = $this->validateEntries($zip);
            $manifest = $this->readManifest($zip);
            $tables = $this->readTables($zip);
            $this->validateManifest($manifest, $tables);
            $this->validateRows($tables);
            $this->crossCheckKmlEntries($tables, $kmlEntries);
            $this->restore($zip, $tables);
        } finally {
            $zip->close();
        }

        return [
            'counts' => array_map('count', $tables),
            'rebindUser' => $this->findRebindUser($tables['users'], $currentUsername),
        ];
    }

    /**
     * Zip-safety pass over every entry, before any content is read: names
     * must be safe (no traversal/absolute paths) AND on the strict archive
     * whitelist, and sizes must stay under the bomb thresholds.
     *
     * @return array<string, true> the set of kml/... entry names
     */
    private function validateEntries(ZipArchive $zip): array
    {
        $kmlEntries = [];
        $totalUncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new InstanceImportException('Could not read archive entry metadata.');
            }

            $name = (string) $stat['name'];
            $this->assertSafeEntryName($name);

            $compressedSize = (int) $stat['comp_size'];
            $uncompressedSize = (int) $stat['size'];
            if ($compressedSize > 0 && ($uncompressedSize / $compressedSize) > self::MAX_COMPRESSION_RATIO) {
                throw new InstanceImportException('An archive entry has a suspicious compression ratio.');
            }
            $totalUncompressed += $uncompressedSize;
            if ($totalUncompressed > $this->maxTotalUncompressedBytes) {
                throw new InstanceImportException('The archive exceeds the maximum allowed uncompressed size.');
            }

            if ($name === 'manifest.json') {
                continue;
            }
            if (preg_match('#^data/([a-z_]+)\.json$#', $name, $m) === 1) {
                if (!in_array($m[1], InstanceExportService::TABLES, true)) {
                    throw new InstanceImportException("Unexpected data file in archive: {$name}");
                }
                continue;
            }
            if (preg_match('#^kml/circuits/([0-9a-fA-F-]{36})/(current\.kml|versions/v[1-9][0-9]*\.kml)$#', $name, $m) === 1
                && Uuid::isValid($m[1])
            ) {
                $kmlEntries[$name] = true;
                continue;
            }

            throw new InstanceImportException("Unexpected entry in archive: {$name}");
        }

        return $kmlEntries;
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new InstanceImportException('The archive contains an entry with an unsafe name.');
        }
        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $name) === 1) {
            throw new InstanceImportException('The archive contains an absolute entry path.');
        }
        foreach (explode('/', str_replace('\\', '/', $name)) as $segment) {
            if ($segment === '..') {
                throw new InstanceImportException('The archive contains a path-traversal entry name.');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');
        if ($raw === false) {
            throw new InstanceImportException('The archive has no manifest.json — not a CircuitMap instance export.');
        }
        $manifest = json_decode($raw, true);
        if (!is_array($manifest)) {
            throw new InstanceImportException('The archive manifest is not valid JSON.');
        }
        return $manifest;
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    private function validateManifest(array $manifest, array $tables): void
    {
        if (($manifest['format'] ?? null) !== InstanceExportService::FORMAT) {
            throw new InstanceImportException('The archive is not a CircuitMap instance export.');
        }
        if (($manifest['format_version'] ?? null) !== InstanceExportService::FORMAT_VERSION) {
            throw new InstanceImportException('The archive uses an unsupported export format version.');
        }

        $archiveMigrations = $manifest['migrations'] ?? null;
        if (!is_array($archiveMigrations) || $archiveMigrations !== array_filter($archiveMigrations, 'is_string')) {
            throw new InstanceImportException('The archive manifest has an invalid migrations list.');
        }
        $applied = $this->pdo->query('SELECT filename FROM schema_migrations ORDER BY filename')
            ->fetchAll(\PDO::FETCH_COLUMN);
        sort($archiveMigrations);
        if ($archiveMigrations !== $applied) {
            throw new InstanceImportException(
                'The export was made by a different CircuitMap version (database schema mismatch). '
                . 'Upgrade both instances to the same version and export again.'
            );
        }

        $counts = $manifest['counts'] ?? null;
        if (!is_array($counts)) {
            throw new InstanceImportException('The archive manifest has an invalid counts map.');
        }
        foreach (InstanceExportService::TABLES as $table) {
            if (($counts[$table] ?? null) !== count($tables[$table])) {
                throw new InstanceImportException("The archive is inconsistent: {$table} row count does not match its manifest.");
            }
        }

        $sequences = $manifest['sequences'] ?? [];
        if (!is_array($sequences)) {
            throw new InstanceImportException('The archive manifest has an invalid sequences map.');
        }
        foreach ($sequences as $table => $seq) {
            if (!in_array($table, InstanceExportService::TABLES, true) || !is_int($seq)) {
                throw new InstanceImportException('The archive manifest has an invalid sequences map.');
            }
        }
        $this->sequences = $sequences;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function readTables(ZipArchive $zip): array
    {
        $tables = [];
        foreach (InstanceExportService::TABLES as $table) {
            $raw = $zip->getFromName("data/{$table}.json");
            if ($raw === false) {
                throw new InstanceImportException("The archive is missing data/{$table}.json.");
            }
            $rows = json_decode($raw, true);
            if (!is_array($rows) || $rows !== array_values($rows)) {
                throw new InstanceImportException("data/{$table}.json is not a JSON array of rows.");
            }
            $tables[$table] = $rows;
        }
        return $tables;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    private function validateRows(array $tables): void
    {
        $idsByTable = [];
        foreach (InstanceExportService::TABLES as $table) {
            $validColumns = $this->tableColumns($table);
            $expectedKeys = null;
            $ids = [];

            foreach ($tables[$table] as $index => $row) {
                if (!is_array($row)) {
                    throw new InstanceImportException("data/{$table}.json row {$index} is not an object.");
                }
                $keys = array_keys($row);
                if ($expectedKeys === null) {
                    foreach ($keys as $key) {
                        if (!is_string($key) || !isset($validColumns[$key])) {
                            throw new InstanceImportException("data/{$table}.json contains an unknown column.");
                        }
                    }
                    if (!in_array('id', $keys, true)) {
                        throw new InstanceImportException("data/{$table}.json rows are missing the id column.");
                    }
                    $expectedKeys = $keys;
                } elseif ($keys !== $expectedKeys) {
                    throw new InstanceImportException("data/{$table}.json rows do not all share the same columns.");
                }

                foreach ($row as $value) {
                    // Bools are rejected too: PDO binds false as '' which
                    // would silently corrupt integer columns.
                    if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
                        throw new InstanceImportException("data/{$table}.json contains a value of an unsupported type.");
                    }
                }

                $id = $row['id'];
                if (!is_int($id) || $id < 1 || isset($ids[$id])) {
                    throw new InstanceImportException("data/{$table}.json contains a missing or duplicate row id.");
                }
                $ids[$id] = true;
            }

            $idsByTable[$table] = $ids;
        }

        foreach (self::FOREIGN_KEYS as $table => $references) {
            foreach ($tables[$table] as $row) {
                foreach ($references as $column => [$referencedTable, $nullable]) {
                    $value = $row[$column] ?? null;
                    if ($value === null) {
                        if (!$nullable) {
                            throw new InstanceImportException("data/{$table}.json has a row with a missing {$column}.");
                        }
                        continue;
                    }
                    if (!is_int($value) || !isset($idsByTable[$referencedTable][$value])) {
                        throw new InstanceImportException(
                            "data/{$table}.json references a {$referencedTable} row that is not in the archive ({$column}={$value})."
                        );
                    }
                }
            }
        }

        foreach ($tables['circuits'] as $row) {
            if (!is_string($row['uuid'] ?? null) || !Uuid::isValid($row['uuid'])) {
                throw new InstanceImportException('data/circuits.json contains an invalid circuit uuid.');
            }
        }

        $hasActiveAdmin = false;
        foreach ($tables['users'] as $row) {
            if (($row['role'] ?? null) === 'admin' && (int) ($row['is_active'] ?? 0) === 1) {
                $hasActiveAdmin = true;
                break;
            }
        }
        if (!$hasActiveAdmin) {
            throw new InstanceImportException(
                'The archive contains no active admin user; importing it would lock everyone out.'
            );
        }
    }

    /**
     * Every circuit and version row must have its KML entry, and every KML
     * entry must belong to a row — exact set equality, both directions.
     *
     * @param array<string, array<int, array<string, mixed>>> $tables
     * @param array<string, true> $kmlEntries
     */
    private function crossCheckKmlEntries(array $tables, array $kmlEntries): void
    {
        $expected = [];
        $uuidsById = [];
        foreach ($tables['circuits'] as $row) {
            $uuid = (string) $row['uuid'];
            $uuidsById[(int) $row['id']] = $uuid;
            $expected["kml/circuits/{$uuid}/current.kml"] = true;
        }
        foreach ($tables['circuit_versions'] as $row) {
            $uuid = $uuidsById[(int) $row['circuit_id']];
            $number = $row['version_number'] ?? null;
            if (!is_int($number) || $number < 1) {
                throw new InstanceImportException('data/circuit_versions.json contains an invalid version number.');
            }
            $expected["kml/circuits/{$uuid}/versions/v{$number}.kml"] = true;
        }

        foreach (array_keys($expected) as $name) {
            if (!isset($kmlEntries[$name])) {
                throw new InstanceImportException("The archive is missing a KML file: {$name}");
            }
        }
        foreach (array_keys($kmlEntries) as $name) {
            if (!isset($expected[$name])) {
                throw new InstanceImportException("The archive contains a KML file no circuit references: {$name}");
            }
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $tables
     */
    private function restore(ZipArchive $zip, array $tables): void
    {
        $writtenUuids = [];
        $this->pdo->beginTransaction();
        try {
            $this->assertTargetIsEmpty();

            // sessions first: it has an FK on users and foreign_keys is ON.
            // Wiping every session is also semantically right for a
            // whole-instance replace.
            $this->pdo->exec('DELETE FROM sessions');
            $this->pdo->exec('DELETE FROM audit_log');
            $this->pdo->exec('DELETE FROM users');

            foreach (InstanceExportService::TABLES as $table) {
                $this->insertRows($table, $tables[$table]);
            }
            $this->restoreSequences();

            $uuidsById = [];
            foreach ($tables['circuits'] as $row) {
                $uuid = (string) $row['uuid'];
                $uuidsById[(int) $row['id']] = $uuid;
                $writtenUuids[$uuid] = true;
                $this->storage->saveNew($uuid, $this->readKmlEntry($zip, "kml/circuits/{$uuid}/current.kml"));
            }
            foreach ($tables['circuit_versions'] as $row) {
                $uuid = $uuidsById[(int) $row['circuit_id']];
                $number = (int) $row['version_number'];
                $this->storage->writeVersion(
                    $uuid,
                    $number,
                    $this->readKmlEntry($zip, "kml/circuits/{$uuid}/versions/v{$number}.kml")
                );
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            foreach (array_keys($writtenUuids) as $uuid) {
                $this->storage->deleteCircuitDir($uuid);
            }
            if ($e instanceof InstanceImportException) {
                throw $e;
            }
            throw new InstanceImportException('The import failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    private function assertTargetIsEmpty(): void
    {
        foreach (['circuits', 'circuit_versions', 'circuit_providers', 'locations'] as $table) {
            $count = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
            if ($count !== 0) {
                throw new InstanceImportException(
                    'This CircuitMap already contains data (' . $table . '). Import is only allowed into a fresh, empty instance.'
                );
            }
        }

        $userCount = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($userCount > 1) {
            throw new InstanceImportException(
                'This CircuitMap already has user accounts beyond the bootstrap admin. Import is only allowed into a fresh, empty instance.'
            );
        }

        if (!$this->storage->circuitsRootIsEmpty()) {
            throw new InstanceImportException(
                'The KML storage directory is not empty. Import is only allowed into a fresh, empty instance.'
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        // Column names were validated against PRAGMA table_info and are
        // identical across rows, so building the statement once is safe.
        $columns = array_keys($rows[0]);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $stmt = $this->pdo->prepare($sql);
        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    /**
     * Explicit-id inserts already advance sqlite_sequence to max(id); this
     * pushes each counter the rest of the way so the copy allocates the
     * same future ids the source would have.
     */
    private function restoreSequences(): void
    {
        $update = $this->pdo->prepare('UPDATE sqlite_sequence SET seq = :seq WHERE name = :name AND seq < :seq');
        $insert = $this->pdo->prepare('INSERT INTO sqlite_sequence (name, seq) VALUES (:name, :seq)');
        foreach ($this->sequences as $table => $seq) {
            $update->execute(['seq' => $seq, 'name' => $table]);
            if ($update->rowCount() === 0) {
                $exists = $this->pdo->prepare('SELECT COUNT(*) FROM sqlite_sequence WHERE name = :name');
                $exists->execute(['name' => $table]);
                if ((int) $exists->fetchColumn() === 0) {
                    $insert->execute(['name' => $table, 'seq' => $seq]);
                }
            }
        }
    }

    private function readKmlEntry(ZipArchive $zip, string $name): string
    {
        $content = $zip->getFromName($name);
        if ($content === false) {
            throw new InstanceImportException("Could not read {$name} from the archive.");
        }
        return $content;
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return array<string, mixed>|null
     */
    private function findRebindUser(array $users, ?string $currentUsername): ?array
    {
        if ($currentUsername === null) {
            return null;
        }
        foreach ($users as $user) {
            if (($user['username'] ?? null) === $currentUsername
                && ($user['role'] ?? null) === 'admin'
                && (int) ($user['is_active'] ?? 0) === 1
            ) {
                return $user;
            }
        }
        return null;
    }

    /**
     * @return array<string, true>
     */
    private function tableColumns(string $table): array
    {
        $columns = [];
        foreach ($this->pdo->query("PRAGMA table_info({$table})")->fetchAll(\PDO::FETCH_ASSOC) as $column) {
            $columns[(string) $column['name']] = true;
        }
        return $columns;
    }
}
