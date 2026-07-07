<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Storage;

use CircuitMap\Services\Storage\PendingImportStorage;
use PHPUnit\Framework\TestCase;

final class PendingImportStorageTest extends TestCase
{
    private string $root;
    private PendingImportStorage $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/circuitmap-pending-test-' . uniqid('', true);
        mkdir($this->root, 0770, true);
        $this->storage = new PendingImportStorage($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testSaveAndReadRoundTrip(): void
    {
        $token = $this->storage->save('<kml/>', ['user_id' => 7, 'name' => 'Bundle']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);

        $pending = $this->storage->read($token);
        self::assertNotNull($pending);
        self::assertSame('<kml/>', $pending['kml']);
        self::assertSame(7, $pending['meta']['user_id']);
        self::assertSame('Bundle', $pending['meta']['name']);
        self::assertIsInt($pending['meta']['created_at']);
    }

    public function testMalformedTokensAreRejected(): void
    {
        $this->storage->save('<kml/>', []);

        self::assertNull($this->storage->read('../../etc/passwd'));
        self::assertNull($this->storage->read(strtoupper(str_repeat('a', 32))));
        self::assertNull($this->storage->read('abc123'));
        self::assertNull($this->storage->read(''));
    }

    public function testUnknownTokenReturnsNull(): void
    {
        self::assertNull($this->storage->read(str_repeat('a', 32)));
    }

    public function testExpiredEntryReturnsNullAndIsSweptOnNextSave(): void
    {
        $shortLived = new PendingImportStorage($this->root, 1);
        $token = $shortLived->save('<kml/>', ['user_id' => 1]);

        // Backdate the entry instead of sleeping.
        $metaPath = $this->root . '/pending/' . $token . '/meta.json';
        $meta = json_decode((string) file_get_contents($metaPath), true);
        $meta['created_at'] = time() - 10;
        file_put_contents($metaPath, json_encode($meta));

        self::assertNull($shortLived->read($token));
        self::assertDirectoryExists($this->root . '/pending/' . $token);

        $shortLived->save('<kml/>', ['user_id' => 2]);
        self::assertDirectoryDoesNotExist($this->root . '/pending/' . $token);
    }

    public function testFreshEntriesSurviveSweep(): void
    {
        $first = $this->storage->save('<kml/>', ['user_id' => 1]);
        $this->storage->save('<kml/>', ['user_id' => 2]);

        self::assertNotNull($this->storage->read($first));
    }

    public function testDeleteRemovesEntry(): void
    {
        $token = $this->storage->save('<kml/>', ['user_id' => 1]);

        $this->storage->delete($token);

        self::assertNull($this->storage->read($token));
        self::assertDirectoryDoesNotExist($this->root . '/pending/' . $token);
    }

    public function testDeleteIgnoresMalformedToken(): void
    {
        $this->storage->delete('../..');
        self::assertDirectoryExists($this->root);
    }
}
