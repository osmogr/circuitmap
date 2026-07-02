<?php

declare(strict_types=1);

namespace CircuitMap\Services\RateLimit;

use PDO;

/**
 * Fixed-window rate limiter backed by SQLite, not in-process memory.
 * PHP-FPM's process-pool model means in-process counters do not survive
 * between requests handled by different workers, so state has to be
 * persisted somewhere shared; SQLite is already present and is adequate
 * at this application's traffic scale. If usage grows well beyond an
 * internal low-hundreds-of-circuits tool, move this to Redis instead.
 */
final class RateLimiterService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Records one hit against $bucketKey and returns true if the caller is
     * still within the allowed limit for the current window.
     */
    public function attempt(string $bucketKey, int $windowSeconds, int $maxHits): bool
    {
        $windowStart = gmdate('Y-m-d\TH:i:s\Z', intdiv(time(), $windowSeconds) * $windowSeconds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limit_hits (bucket_key, window_start, count)
             VALUES (:bucket_key, :window_start, 1)
             ON CONFLICT(bucket_key, window_start) DO UPDATE SET count = count + 1'
        );
        $stmt->execute(['bucket_key' => $bucketKey, 'window_start' => $windowStart]);

        $countStmt = $this->pdo->prepare(
            'SELECT count FROM rate_limit_hits WHERE bucket_key = :bucket_key AND window_start = :window_start'
        );
        $countStmt->execute(['bucket_key' => $bucketKey, 'window_start' => $windowStart]);
        $count = (int) $countStmt->fetchColumn();

        // Opportunistic cleanup of old windows; cheap relative to the write
        // above and keeps the table from growing unbounded.
        if (random_int(1, 50) === 1) {
            $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - (10 * $windowSeconds));
            $this->pdo->prepare('DELETE FROM rate_limit_hits WHERE window_start < :cutoff')
                ->execute(['cutoff' => $cutoff]);
        }

        return $count <= $maxHits;
    }
}
