<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class CircuitVersionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        int $circuitId,
        int $versionNumber,
        string $filePath,
        ?string $nameSnapshot,
        ?string $descriptionSnapshot,
        int $editedBy
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO circuit_versions
                (circuit_id, version_number, file_path, name_snapshot, description_snapshot, edited_by, created_at)
             VALUES
                (:circuit_id, :version_number, :file_path, :name_snapshot, :description_snapshot, :edited_by, :now)'
        );
        $stmt->execute([
            'circuit_id' => $circuitId,
            'version_number' => $versionNumber,
            'file_path' => $filePath,
            'name_snapshot' => $nameSnapshot,
            'description_snapshot' => $descriptionSnapshot,
            'edited_by' => $editedBy,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForCircuit(int $circuitId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM circuit_versions WHERE circuit_id = :circuit_id ORDER BY version_number DESC'
        );
        $stmt->execute(['circuit_id' => $circuitId]);
        return $stmt->fetchAll();
    }
}
