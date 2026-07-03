<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Lets the app be mounted under a sub-path (e.g. /circuitmap) behind a
 * reverse proxy that forwards the full prefixed path through unchanged.
 * Every hardcoded internal link/redirect/cookie path must be built via
 * url() rather than as a bare "/..." string, or it will break once
 * BASE_PATH is set to anything other than the default root.
 */
final class BasePath
{
    private static string $value = '';

    public static function configure(string $raw): void
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || $trimmed === '/') {
            self::$value = '';
            return;
        }

        if (preg_match('#^/[A-Za-z0-9._~\-/]+$#', $trimmed) !== 1) {
            self::$value = '';
            return;
        }

        self::$value = rtrim($trimmed, '/');
    }

    public static function get(): string
    {
        return self::$value;
    }

    public static function url(string $path): string
    {
        return self::$value . $path;
    }
}
