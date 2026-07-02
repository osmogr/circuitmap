<?php

declare(strict_types=1);

namespace CircuitMap\Services\Storage;

use CircuitMap\Support\Uuid;

/**
 * The only code path allowed to turn a circuit identifier into a
 * filesystem path. Every path is built from a server-generated UUID that
 * is checked against a strict whitelist regex before any concatenation;
 * user-supplied filenames or circuit names never reach the filesystem.
 */
final class FileStorageService
{
    public function __construct(private readonly string $storageRoot)
    {
    }

    public function saveNew(string $uuid, string $kmlContent): string
    {
        $dir = $this->circuitDir($uuid);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException("Could not create storage directory for circuit {$uuid}.");
        }

        $path = $dir . '/current.kml';
        if (file_put_contents($path, $kmlContent, LOCK_EX) === false) {
            throw new \RuntimeException("Could not write KML file for circuit {$uuid}.");
        }

        return $this->relativePath($uuid);
    }

    public function read(string $uuid): string
    {
        $path = $this->circuitDir($uuid) . '/current.kml';
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read KML file for circuit {$uuid}.");
        }
        return $content;
    }

    public function relativePath(string $uuid): string
    {
        return "circuits/{$uuid}/current.kml";
    }

    /**
     * Copies the existing current.kml to versions/vN.kml. Must be called
     * BEFORE overwriteCurrent() so the pre-edit content is never lost.
     */
    public function archiveCurrent(string $uuid, int $versionNumber): string
    {
        $dir = $this->circuitDir($uuid);
        $versionsDir = $dir . '/versions';
        if (!is_dir($versionsDir) && !mkdir($versionsDir, 0770, true) && !is_dir($versionsDir)) {
            throw new \RuntimeException("Could not create versions directory for circuit {$uuid}.");
        }

        $source = $dir . '/current.kml';
        $destination = $versionsDir . "/v{$versionNumber}.kml";
        if (!copy($source, $destination)) {
            throw new \RuntimeException("Could not archive version {$versionNumber} for circuit {$uuid}.");
        }

        return "circuits/{$uuid}/versions/v{$versionNumber}.kml";
    }

    public function overwriteCurrent(string $uuid, string $kmlContent): void
    {
        $path = $this->circuitDir($uuid) . '/current.kml';
        if (file_put_contents($path, $kmlContent, LOCK_EX) === false) {
            throw new \RuntimeException("Could not overwrite KML file for circuit {$uuid}.");
        }
    }

    public function readVersion(string $uuid, int $versionNumber): string
    {
        $path = $this->circuitDir($uuid) . "/versions/v{$versionNumber}.kml";
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Could not read version {$versionNumber} for circuit {$uuid}.");
        }
        return $content;
    }

    private function circuitDir(string $uuid): string
    {
        if (!Uuid::isValid($uuid)) {
            throw new \InvalidArgumentException('Invalid circuit identifier.');
        }

        return rtrim($this->storageRoot, '/') . '/circuits/' . $uuid;
    }
}
