<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use ZipArchive;

/**
 * Safely extracts the KML payload from a .kmz (zipped KML) upload. Guards
 * against zip-slip (entries that would escape the extraction context) and
 * decompression bombs (tiny compressed size, huge claimed uncompressed
 * size) before any entry content is read.
 */
final class KmzExtractor
{
    public function __construct(
        private readonly int $maxTotalUncompressedBytes = 20_971_520,
        private readonly int $maxCompressionRatio = 100
    ) {
    }

    public function extractKml(string $kmzFilePath): string
    {
        $zip = new ZipArchive();
        $opened = $zip->open($kmzFilePath, ZipArchive::CHECKCONS);
        if ($opened !== true) {
            throw new KmlParseException('Could not open file as a valid KMZ (ZIP) archive.');
        }

        try {
            return $this->extractFromOpenArchive($zip);
        } finally {
            $zip->close();
        }
    }

    private function extractFromOpenArchive(ZipArchive $zip): string
    {
        $totalUncompressed = 0;
        $kmlEntryName = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new KmlParseException('Could not read KMZ archive entry metadata.');
            }

            $name = (string) $stat['name'];
            $this->assertSafeEntryName($name);

            $compressedSize = (int) $stat['comp_size'];
            $uncompressedSize = (int) $stat['size'];

            if ($compressedSize > 0 && ($uncompressedSize / $compressedSize) > $this->maxCompressionRatio) {
                throw new KmlParseException('KMZ entry has a suspicious compression ratio.');
            }

            $totalUncompressed += $uncompressedSize;
            if ($totalUncompressed > $this->maxTotalUncompressedBytes) {
                throw new KmlParseException('KMZ archive exceeds the maximum allowed uncompressed size.');
            }

            if (strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) === 'kml') {
                // Prefer the conventional "doc.kml" name; otherwise take the
                // first .kml entry found.
                if ($kmlEntryName === null || strtolower(basename($name)) === 'doc.kml') {
                    $kmlEntryName = $name;
                }
            }
        }

        if ($kmlEntryName === null) {
            throw new KmlParseException('KMZ archive does not contain a .kml file.');
        }

        $contents = $zip->getFromName($kmlEntryName);
        if ($contents === false) {
            throw new KmlParseException('Could not read the KML entry from the KMZ archive.');
        }

        return $contents;
    }

    private function assertSafeEntryName(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new KmlParseException('KMZ contains an entry with an unsafe name.');
        }

        if (str_starts_with($name, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $name) === 1) {
            throw new KmlParseException('KMZ contains an absolute entry path.');
        }

        $normalized = str_replace('\\', '/', $name);
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new KmlParseException('KMZ contains a path-traversal entry name.');
            }
        }
    }
}
