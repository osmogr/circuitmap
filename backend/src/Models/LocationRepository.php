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
        ?string $icon = null,
        ?int $cactiHostId = null
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO locations
                (name, address, notes, latitude, longitude, icon, cacti_host_id, is_active, created_at, updated_at)
             VALUES
                (:name, :address, :notes, :latitude, :longitude, :icon, :cacti_host_id, 1, :now, :now)'
        );
        $stmt->execute([
            'name' => $name,
            'address' => $address,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'icon' => $icon,
            'cacti_host_id' => $cactiHostId,
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
        ?string $icon = null,
        ?int $cactiHostId = null
    ): void {
        // When the Cacti mapping changes or is cleared, the last polled
        // status no longer describes this row — reset it in the same UPDATE
        // (the stale sweep only touches mapped locations, so a cleared one
        // would otherwise keep its old status forever). The CASE reads the
        // pre-update cacti_host_id; IS is the NULL-safe comparison.
        $stmt = $this->pdo->prepare(
            "UPDATE locations
             SET name = :name, address = :address, notes = :notes,
                 latitude = :latitude, longitude = :longitude, icon = :icon,
                 status = CASE WHEN cacti_host_id IS :cacti_host_id_a THEN status ELSE 'unknown' END,
                 status_updated_at = CASE WHEN cacti_host_id IS :cacti_host_id_b THEN status_updated_at ELSE NULL END,
                 cacti_host_id = :cacti_host_id,
                 updated_at = :now
             WHERE id = :id"
        );
        $stmt->execute([
            'name' => $name,
            'address' => $address,
            'notes' => $notes,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'icon' => $icon,
            'cacti_host_id_a' => $cactiHostId,
            'cacti_host_id_b' => $cactiHostId,
            'cacti_host_id' => $cactiHostId,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    /**
     * Locations linked to a Cacti device, for the status poller. Inactive
     * locations are included on purpose: polling them is cheap and a
     * reactivated site comes back with a current status instead of a stale
     * one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCactiMapped(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, cacti_host_id, status, status_updated_at
             FROM locations
             WHERE cacti_host_id IS NOT NULL'
        );
        return $stmt->fetchAll();
    }

    /**
     * Poller-only status write. Bumps status_updated_at but deliberately
     * not updated_at, so polling doesn't make locations look freshly
     * edited.
     */
    public function updateStatusFromPoller(int $id, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE locations
             SET status = :status, status_updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    /**
     * Flips Cacti-mapped locations whose status is older than the cutoff to
     * 'unknown' — used when Cacti itself is unreachable, so a dead poller
     * can't leave sites green forever.
     *
     * @return int number of locations flipped
     */
    public function markStaleStatusesUnknown(string $cutoffIso): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE locations
             SET status = 'unknown'
             WHERE cacti_host_id IS NOT NULL
               AND status != 'unknown'
               AND (status_updated_at IS NULL OR status_updated_at < :cutoff)"
        );
        $stmt->execute(['cutoff' => $cutoffIso]);
        return $stmt->rowCount();
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
