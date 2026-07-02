<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Single source of truth for status-to-color mapping, so the API and any
 * future rendering path can't drift on what "degraded" looks like.
 */
final class StatusColor
{
    private const MAP = [
        'up' => '#16a34a',
        'degraded' => '#d97706',
        'down' => '#dc2626',
        'unknown' => '#6b7280',
    ];

    public static function forStatus(?string $status): string
    {
        return self::MAP[$status ?? 'unknown'] ?? self::MAP['unknown'];
    }
}
