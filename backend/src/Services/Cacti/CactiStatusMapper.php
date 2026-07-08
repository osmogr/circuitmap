<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

/**
 * Translates Cacti's host.status codes into circuit status values
 * (the CHECK-constrained enum on the circuits table).
 */
final class CactiStatusMapper
{
    private const HOST_DOWN = 1;
    private const HOST_RECOVERING = 2;
    private const HOST_UP = 3;

    /**
     * @param array{status: int, disabled: bool}|null $host null = host id
     *        not present in Cacti at all (typo'd mapping, deleted device).
     */
    public static function toCircuitStatus(?array $host): string
    {
        if ($host === null || $host['disabled']) {
            return 'unknown';
        }

        return match ($host['status']) {
            self::HOST_UP => 'up',
            self::HOST_RECOVERING => 'degraded',
            self::HOST_DOWN => 'down',
            default => 'unknown', // 0 = not monitored/unknown
        };
    }
}
