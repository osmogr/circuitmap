<?php

declare(strict_types=1);

namespace CircuitMap\Services\Storage;

/**
 * Holds an uploaded, already-sanitized KML between the split-preview and
 * split-confirm requests. Like FileStorageService, this is the only code
 * path allowed to turn a pending-import identifier into a filesystem path:
 * every path is built from a server-generated random token that is checked
 * against a strict whitelist regex before any concatenation; user input
 * never reaches the filesystem. Abandoned imports are swept opportunistically
 * on each save(), so no cron or daemon is needed.
 */
final class PendingImportStorage
{
    private const TOKEN_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private readonly string $storageRoot,
        private readonly int $ttlSeconds = 3600
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     * @return string the new pending-import token
     */
    public function save(string $kmlXml, array $meta): string
    {
        $this->sweepExpired();

        $token = bin2hex(random_bytes(16));
        $dir = $this->pendingDir($token);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create pending import directory.');
        }

        $meta['created_at'] = time();
        if (file_put_contents($dir . '/source.kml', $kmlXml, LOCK_EX) === false
            || file_put_contents($dir . '/meta.json', json_encode($meta, JSON_THROW_ON_ERROR), LOCK_EX) === false
        ) {
            throw new \RuntimeException('Could not write pending import files.');
        }

        return $token;
    }

    /**
     * @return array{kml: string, meta: array<string, mixed>}|null
     *   null when the token is malformed, unknown, or expired
     */
    public function read(string $token): ?array
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        $dir = $this->pendingDir($token);
        $kml = @file_get_contents($dir . '/source.kml');
        $metaJson = @file_get_contents($dir . '/meta.json');
        if ($kml === false || $metaJson === false) {
            return null;
        }

        $meta = json_decode($metaJson, true);
        if (!is_array($meta) || $this->isExpired($meta)) {
            return null;
        }

        return ['kml' => $kml, 'meta' => $meta];
    }

    public function delete(string $token): void
    {
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return;
        }
        $this->removeDir($this->pendingDir($token));
    }

    private function sweepExpired(): void
    {
        $root = $this->pendingRoot();
        $entries = @scandir($root);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if (preg_match(self::TOKEN_PATTERN, $entry) !== 1) {
                continue;
            }
            $metaJson = @file_get_contents($root . '/' . $entry . '/meta.json');
            $meta = $metaJson === false ? null : json_decode($metaJson, true);
            // Unreadable or corrupt entries are treated as expired.
            if (!is_array($meta) || $this->isExpired($meta)) {
                $this->removeDir($root . '/' . $entry);
            }
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function isExpired(array $meta): bool
    {
        $createdAt = $meta['created_at'] ?? null;
        return !is_int($createdAt) || (time() - $createdAt) > $this->ttlSeconds;
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function pendingDir(string $token): string
    {
        return $this->pendingRoot() . '/' . $token;
    }

    private function pendingRoot(): string
    {
        return rtrim($this->storageRoot, '/') . '/pending';
    }
}
