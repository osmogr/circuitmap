<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class CircuitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        string $uuid,
        string $name,
        ?string $description,
        ?string $tags,
        int $ownerId,
        string $currentFilePath
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO circuits
                (uuid, name, description, tags, owner_id, current_file_path, current_version,
                 status, uploaded_at, updated_at)
             VALUES
                (:uuid, :name, :description, :tags, :owner_id, :current_file_path, 1,
                 \'unknown\', :now, :now)'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
            'owner_id' => $ownerId,
            'current_file_path' => $currentFilePath,
            'now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVisible(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, uuid, name, description, tags, owner_id, status, status_source,
                    status_updated_at, color, uploaded_at, updated_at
             FROM circuits
             WHERE deleted_at IS NULL
             ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUuid(string $uuid): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM circuits WHERE uuid = :uuid AND deleted_at IS NULL LIMIT 1');
        $stmt->execute(['uuid' => $uuid]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateAfterEdit(
        int $id,
        string $name,
        ?string $description,
        ?string $tags,
        int $newVersionNumber
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE circuits
             SET name = :name, description = :description, tags = :tags,
                 current_version = :version, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
            'version' => $newVersionNumber,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    public function updateAfterRevert(int $id, int $newVersionNumber): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE circuits SET current_version = :version, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'version' => $newVersionNumber,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    public function updateStatus(int $id, string $status, string $source): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'UPDATE circuits
             SET status = :status, status_source = :source, status_updated_at = :now, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'source' => $source,
            'now' => $now,
            'id' => $id,
        ]);
    }

    public function softDelete(int $id): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare('UPDATE circuits SET deleted_at = :now, updated_at = :now WHERE id = :id');
        $stmt->execute(['now' => $now, 'id' => $id]);
    }
}
