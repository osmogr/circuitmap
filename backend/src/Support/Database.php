<?php

declare(strict_types=1);

namespace CircuitMap\Support;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $path = Env::get('DB_PATH', '/var/lib/circuitmap/db/circuitmap.sqlite');
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }

            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');

            self::$connection = $pdo;
        }

        return self::$connection;
    }

    /**
     * Used by tests to point at an isolated database file.
     */
    public static function reset(): void
    {
        self::$connection = null;
    }
}
