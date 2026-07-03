<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class LocationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        string $name,
        ?string $address,
        ?string $notes,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $icon = null
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO locations
                (name, address, notes, latitude, longitude, icon, is_active, created_at, updated_at)
             VALUES
                (:name, :address, :notes, :latitude, :longitude, :icon, 1, :now, :now)'
        );
        $stmt->execute([
            'name' => $name,
            'address' => $address,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'icon' => $icon,
            'now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM locations ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM locations WHERE is_active = 1 ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * Active locations with a valid, geolocated address — the set that can
     * be plotted as a map marker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMappable(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM locations
             WHERE is_active = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL
             ORDER BY name'
        );
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM locations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM locations WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function update(
        int $id,
        string $name,
        ?string $address,
        ?string $notes,
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $icon = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE locations
             SET name = :name, address = :address, notes = :notes,
                 latitude = :latitude, longitude = :longitude, icon = :icon,
                 updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'address' => $address,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'icon' => $icon,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE locations SET is_active = :active, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'active' => $active ? 1 : 0,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }
}
