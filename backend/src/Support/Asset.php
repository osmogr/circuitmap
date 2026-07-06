<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Builds asset URLs with a cache-busting version derived from the file's
 * mtime. nginx serves /assets/ with a 1-day Cache-Control, so without a
 * changing query string browsers keep stale CSS/JS across deploys.
 */
final class Asset
{
    /** @var array<string, string> */
    private static array $cache = [];

    private static string $frontendRoot = '/var/www/frontend';

    public static function configure(string $frontendRoot): void
    {
        if (trim($frontendRoot) !== '') {
            self::$frontendRoot = rtrim($frontendRoot, '/');
        }
        self::$cache = [];
    }

    /**
     * @param string $path URL path beginning with /assets/ (nginx aliases
     *                     that prefix to the frontend root on disk)
     */
    public static function url(string $path): string
    {
        if (!isset(self::$cache[$path])) {
            $file = self::$frontendRoot . substr($path, strlen('/assets'));
            $mtime = @filemtime($file);
            self::$cache[$path] = BasePath::url($path) . ($mtime === false ? '' : '?v=' . $mtime);
        }
        return self::$cache[$path];
    }
}
