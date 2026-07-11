<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Storage;

use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class FileStorageServiceTest extends TestCase
{
    private string $root;
    private FileStorageService $storage;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/circuitmap-storage-test-' . uniqid('', true);
        mkdir($this->root, 0770, true);
        $this->storage = new FileStorageService($this->root);
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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function maliciousIdentifiers(): iterable
    {
        yield 'parent traversal' => ['../../etc/passwd'];
        yield 'windows traversal' => ['..\\..\\windows\\win.ini'];
        yield 'null byte' => ["circuit\0.kml"];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'not a uuid at all' => ['some circuit name'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousIdentifiers')]
    public function testMaliciousIdentifiersNeverReachTheFilesystem(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->saveNew($identifier, '<kml/>');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousIdentifiers')]
    public function testWriteVersionRejectsMaliciousIdentifiers(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->writeVersion($identifier, 1, '<kml/>');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousIdentifiers')]
    public function testDeleteCircuitDirRejectsMaliciousIdentifiers(string $identifier): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->storage->deleteCircuitDir($identifier);
    }

    public function testMaliciousIdentifierCannotEscapeStorageRootEvenIfExceptionWereMissed(): void
    {
        // Even if the guard were somehow bypassed, confirm no file lands
        // outside the storage root for a directory-traversal identifier.
        try {
            $this->storage->saveNew('../../../../tmp/circuitmap-escape-test', '<kml/>');
        } catch (\InvalidArgumentException $e) {
            // expected
        }

        $this->assertFileDoesNotExist('/tmp/circuitmap-escape-test');
        $escapedPath = realpath(dirname($this->root)) . '/circuitmap-escape-test';
        $this->assertFileDoesNotExist($escapedPath);
    }

    public function testValidUuidIsAcceptedAndStoredUnderStorageRoot(): void
    {
        $uuid = Uuid::v4();
        $relativePath = $this->storage->saveNew($uuid, '<kml>content</kml>');

        $this->assertSame("circuits/{$uuid}/current.kml", $relativePath);
        $fullPath = $this->root . '/' . $relativePath;
        $this->assertFileExists($fullPath);

        $real = realpath($fullPath);
        $this->assertNotFalse($real);
        $this->assertStringStartsWith(realpath($this->root) . DIRECTORY_SEPARATOR, $real);
    }

    public function testReadReturnsWhatWasWritten(): void
    {
        $uuid = Uuid::v4();
        $this->storage->saveNew($uuid, '<kml>hello</kml>');

        $this->assertSame('<kml>hello</kml>', $this->storage->read($uuid));
    }

    public function testWriteVersionStoresContentReadableByReadVersion(): void
    {
        $uuid = Uuid::v4();
        $this->storage->saveNew($uuid, '<kml>current</kml>');
        $relativePath = $this->storage->writeVersion($uuid, 3, '<kml>v3</kml>');

        $this->assertSame("circuits/{$uuid}/versions/v3.kml", $relativePath);
        $this->assertSame('<kml>v3</kml>', $this->storage->readVersion($uuid, 3));
    }

    public function testDeleteCircuitDirRemovesCurrentAndVersions(): void
    {
        $uuid = Uuid::v4();
        $this->storage->saveNew($uuid, '<kml>current</kml>');
        $this->storage->writeVersion($uuid, 1, '<kml>v1</kml>');

        $this->storage->deleteCircuitDir($uuid);

        $this->assertDirectoryDoesNotExist($this->root . '/circuits/' . $uuid);
    }

    public function testCircuitsRootIsEmptyReflectsStoredCircuits(): void
    {
        $this->assertTrue($this->storage->circuitsRootIsEmpty());

        $uuid = Uuid::v4();
        $this->storage->saveNew($uuid, '<kml/>');
        $this->assertFalse($this->storage->circuitsRootIsEmpty());

        $this->storage->deleteCircuitDir($uuid);
        $this->assertTrue($this->storage->circuitsRootIsEmpty());
    }
}
