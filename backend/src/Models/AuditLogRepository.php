<?php

declare(strict_types=1);

namespace CircuitMap\Models;

use PDO;

final class AuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        ?int $userId,
        string $eventType,
        ?int $circuitId = null,
        ?string $detail = null,
        ?string $ipAddress = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, event_type, circuit_id, detail, ip_address, created_at)
             VALUES (:user_id, :event_type, :circuit_id, :detail, :ip_address, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'circuit_id' => $circuitId,
            'detail' => $detail,
            'ip_address' => $ipAddress,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
