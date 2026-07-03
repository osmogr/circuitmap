<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Single source of truth for the fixed set of map icons a Location can be
 * configured with, so the admin form and the map API can't drift.
 */
final class LocationIcon
{
    private const MAP = [
        'generic' => ['label' => 'Generic site', 'symbol' => '📍'],
        'office' => ['label' => 'Office', 'symbol' => '🏢'],
        'data-center' => ['label' => 'Data center', 'symbol' => '🖥️'],
        'warehouse' => ['label' => 'Warehouse', 'symbol' => '🏭'],
        'tower' => ['label' => 'Tower / POP', 'symbol' => '📡'],
        'retail' => ['label' => 'Retail', 'symbol' => '🏬'],
    ];

    /**
     * @return array<string, array{label: string, symbol: string}>
     */
    public static function options(): array
    {
        return self::MAP;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    public static function symbolFor(?string $key): string
    {
        return self::MAP[$key ?? 'generic']['symbol'] ?? self::MAP['generic']['symbol'];
    }
}
