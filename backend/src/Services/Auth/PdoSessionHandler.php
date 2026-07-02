<?php

declare(strict_types=1);

namespace CircuitMap\Services\Auth;

use PDO;
use SessionHandlerInterface;

/**
 * Sessions must survive container restarts (everything persists on a
 * volume per the project's requirements), so the default "files" handler
 * (writing to ephemeral container-local disk) is not used here.
 */
final class PdoSessionHandler implements SessionHandlerInterface
{
    private const MAX_LIFETIME_SECONDS = 86400;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return '';
        }
        return (string) $row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $userId = $_SESSION['user_id'] ?? null;
        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, last_activity)
             VALUES (:id, :user_id, :data, :now)
             ON CONFLICT(id) DO UPDATE SET
                user_id = excluded.user_id,
                data = excluded.data,
                last_activity = excluded.last_activity'
        );
        return $stmt->execute([
            'id' => $id,
            'user_id' => $userId,
            'data' => $data,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        return $stmt->execute(['id' => $id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $threshold = gmdate('Y-m-d\TH:i:s\Z', time() - self::MAX_LIFETIME_SECONDS);
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE last_activity < :threshold');
        $stmt->execute(['threshold' => $threshold]);
        return $stmt->rowCount();
    }
}
