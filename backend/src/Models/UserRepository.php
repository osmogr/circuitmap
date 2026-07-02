<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class UserRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM users');
        return (int) $stmt->fetchColumn();
    }

    public function create(string $username, string $passwordHash, string $role, ?string $displayName = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, display_name, is_active, created_at)
             VALUES (:username, :password_hash, :role, :display_name, 1, :created_at)'
        );
        $stmt->execute([
            'username' => $username,
            'password_hash' => $passwordHash,
            'role' => $role,
            'display_name' => $displayName,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function updateLastLogin(int $userId): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET last_login_at = :now WHERE id = :id');
        $stmt->execute([
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
            'id' => $userId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT id, username, role, display_name, is_active, created_at, last_login_at
                                    FROM users ORDER BY username');
        return $stmt->fetchAll();
    }

    public function setActive(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET is_active = :active WHERE id = :id');
        $stmt->execute(['active' => $active ? 1 : 0, 'id' => $id]);
    }

    public function setRole(int $id, string $role): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute(['role' => $role, 'id' => $id]);
    }
}
