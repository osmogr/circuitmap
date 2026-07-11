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
        string $currentFilePath,
        ?int $providerId = null,
        ?string $providerCircuitId = null,
        ?string $orderNumber = null,
        bool $redundant = false,
        ?int $aLocationId = null,
        ?int $zLocationId = null
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO circuits
                (uuid, name, description, tags, owner_id, current_file_path, current_version,
                 status, provider_id, provider_circuit_id, order_number, redundant,
                 a_location_id, z_location_id, uploaded_at, updated_at)
             VALUES
                (:uuid, :name, :description, :tags, :owner_id, :current_file_path, 1,
                 \'unknown\', :provider_id, :provider_circuit_id, :order_number, :redundant,
                 :a_location_id, :z_location_id, :now, :now)'
        );
        $stmt->execute([
            'uuid' => $uuid,
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
            'owner_id' => $ownerId,
            'current_file_path' => $currentFilePath,
            'provider_id' => $providerId,
            'provider_circuit_id' => $providerCircuitId,
            'order_number' => $orderNumber,
            'redundant' => $redundant ? 1 : 0,
            'a_location_id' => $aLocationId,
            'z_location_id' => $zLocationId,
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
            'SELECT c.id, c.uuid, c.name, c.description, c.tags, c.owner_id, c.status, c.status_source,
                    c.status_updated_at, c.color, c.provider_id, c.provider_circuit_id, c.order_number,
                    c.redundant, c.a_location_id, c.z_location_id, c.uploaded_at, c.updated_at,
                    c.cacti_local_data_id, c.capacity_bps,
                    c.usage_in_bps, c.usage_out_bps, c.usage_updated_at,
                    p.name AS provider_name, p.tech_support_number AS provider_tech_support_number,
                    p.account_id AS provider_account_id, p.local_rep_contact AS provider_local_rep_contact,
                    la.name AS a_location_name, lz.name AS z_location_name
             FROM circuits c
             LEFT JOIN circuit_providers p ON p.id = c.provider_id
             LEFT JOIN locations la ON la.id = c.a_location_id
             LEFT JOIN locations lz ON lz.id = c.z_location_id
             WHERE c.deleted_at IS NULL
             ORDER BY c.name'
        );
        return $stmt->fetchAll();
    }

    /**
     * Every circuits column (c.* keeps this in sync with schema changes)
     * plus the joined provider and location names — the CSV export of the
     * All Circuits report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listVisibleAllColumns(): array
    {
        $stmt = $this->pdo->query(
            'SELECT c.*, p.name AS provider_name,
                    la.name AS a_location_name, lz.name AS z_location_name
             FROM circuits c
             LEFT JOIN circuit_providers p ON p.id = c.provider_id
             LEFT JOIN locations la ON la.id = c.a_location_id
             LEFT JOIN locations lz ON lz.id = c.z_location_id
             WHERE c.deleted_at IS NULL
             ORDER BY c.name'
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
        int $newVersionNumber,
        ?int $providerId = null,
        ?string $providerCircuitId = null,
        ?string $orderNumber = null,
        bool $redundant = false,
        ?int $aLocationId = null,
        ?int $zLocationId = null,
        ?int $cactiLocalDataId = null,
        ?int $capacityBps = null
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE circuits
             SET name = :name, description = :description, tags = :tags,
                 provider_id = :provider_id, provider_circuit_id = :provider_circuit_id,
                 order_number = :order_number, redundant = :redundant,
                 a_location_id = :a_location_id, z_location_id = :z_location_id,
                 cacti_local_data_id = :cacti_local_data_id,
                 capacity_bps = :capacity_bps,
                 current_version = :version, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'tags' => $tags,
            'provider_id' => $providerId,
            'provider_circuit_id' => $providerCircuitId,
            'order_number' => $orderNumber,
            'redundant' => $redundant ? 1 : 0,
            'a_location_id' => $aLocationId,
            'z_location_id' => $zLocationId,
            'cacti_local_data_id' => $cactiLocalDataId,
            'capacity_bps' => $capacityBps,
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

    /**
     * Circuits mapped to a Cacti traffic data source, for the poller.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTrafficMapped(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, uuid, cacti_local_data_id
             FROM circuits
             WHERE deleted_at IS NULL AND cacti_local_data_id IS NOT NULL'
        );
        return $stmt->fetchAll();
    }

    public function updateUsage(int $id, ?int $inBps, ?int $outBps): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE circuits
             SET usage_in_bps = :in_bps, usage_out_bps = :out_bps, usage_updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'in_bps' => $inBps,
            'out_bps' => $outBps,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
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
