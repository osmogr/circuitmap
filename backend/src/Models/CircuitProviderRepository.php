<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class CircuitProviderRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(
        string $name,
        ?string $techSupportNumber,
        ?string $accountId,
        ?string $localRepContact
    ): int {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO circuit_providers
                (name, tech_support_number, account_id, local_rep_contact, is_active, created_at, updated_at)
             VALUES
                (:name, :tech_support_number, :account_id, :local_rep_contact, 1, :now, :now)'
        );
        $stmt->execute([
            'name' => $name,
            'tech_support_number' => $techSupportNumber,
            'account_id' => $accountId,
            'local_rep_contact' => $localRepContact,
            'now' => $now,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM circuit_providers ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listActive(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM circuit_providers WHERE is_active = 1 ORDER BY name');
        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM circuit_providers WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByName(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM circuit_providers WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $name]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function update(
        int $id,
        string $name,
        ?string $techSupportNumber,
        ?string $accountId,
        ?string $localRepContact
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE circuit_providers
             SET name = :name, tech_support_number = :tech_support_number, account_id = :account_id,
                 local_rep_contact = :local_rep_contact, updated_at = :now
             WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'tech_support_number' => $techSupportNumber,
            'account_id' => $accountId,
            'local_rep_contact' => $localRepContact,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE circuit_providers SET is_active = :active, updated_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'active' => $active ? 1 : 0,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $id,
        ]);
    }
}
