<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmzExtractor;
use PHPUnit\Framework\TestCase;

final class KmzExtractorTest extends TestCase
{
    private KmzExtractor $extractor;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->extractor = new KmzExtractor();
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures';
    }

    public function testValidKmzExtractsKmlContent(): void
    {
        $kml = $this->extractor->extractKml($this->fixturesDir . '/valid_simple.kmz');

        $this->assertStringContainsString('<kml', $kml);
        $this->assertStringContainsString('Placemark', $kml);
    }

    public function testZipSlipEntryIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/path-traversal/');
        $this->extractor->extractKml($this->fixturesDir . '/zip_slip.kmz');
    }

    public function testDecompressionBombIsRejectedBeforeFullExtraction(): void
    {
        // A small extractor limit makes this fast and deterministic without
        // needing a truly enormous fixture file.
        $extractor = new KmzExtractor(maxTotalUncompressedBytes: 1_048_576);

        $this->expectException(KmlParseException::class);
        $extractor->extractKml($this->fixturesDir . '/zip_bomb.kmz');
    }

    public function testNotAZipFileIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/valid KMZ/');
        $this->extractor->extractKml($this->fixturesDir . '/invalid_malformed.kml');
    }

    public function testKmzWithNoKmlEntryIsRejected(): void
    {
        $emptyZip = sys_get_temp_dir() . '/circuitmap-empty-' . uniqid('', true) . '.kmz';
        $zip = new \ZipArchive();
        $zip->open($emptyZip, \ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'no kml here');
        $zip->close();

        try {
            $this->expectException(KmlParseException::class);
            $this->expectExceptionMessageMatches('/does not contain a \.kml/');
            $this->extractor->extractKml($emptyZip);
        } finally {
            @unlink($emptyZip);
        }
    }
}
