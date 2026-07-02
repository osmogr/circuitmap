<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use PHPUnit\Framework\TestCase;

final class KmlParserTest extends TestCase
{
    private KmlParser $parser;
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->parser = new KmlParser();
        $this->fixturesDir = dirname(__DIR__, 2) . '/fixtures';
    }

    public function testValidKmlParsesSuccessfully(): void
    {
        $xml = file_get_contents($this->fixturesDir . '/valid_simple.kml');
        $dom = $this->parser->parse((string) $xml);

        $this->assertSame('kml', $dom->documentElement->localName);
        $this->assertGreaterThan(0, $dom->getElementsByTagName('Placemark')->length);
    }

    public function testMalformedXmlThrowsCaughtException(): void
    {
        $xml = file_get_contents($this->fixturesDir . '/invalid_malformed.kml');

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/Malformed XML/');
        $this->parser->parse((string) $xml);
    }

    public function testDoctypeIsRejectedRegardlessOfEntityContent(): void
    {
        $xml = file_get_contents($this->fixturesDir . '/xxe_attempt.kml');

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/DOCTYPE/');
        $this->parser->parse((string) $xml);
    }

    public function testXxeAttemptNeverLeaksFileContent(): void
    {
        $xml = file_get_contents($this->fixturesDir . '/xxe_attempt.kml');

        try {
            $dom = $this->parser->parse((string) $xml);
            // If somehow parsed, the entity must never have been expanded.
            $this->assertStringNotContainsString('root:', $dom->saveXML());
        } catch (KmlParseException $e) {
            $this->assertStringNotContainsString('root:', $e->getMessage());
        }
    }

    public function testEmptyInputIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->parser->parse('');
    }

    public function testOversizedInputIsRejected(): void
    {
        $parser = new KmlParser(maxBytes: 10);
        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/maximum allowed size/');
        $parser->parse(str_repeat('a', 100));
    }

    public function testDeeplyNestedXmlDoesNotExhaustResources(): void
    {
        $depth = 5000;
        $xml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2">'
            . str_repeat('<a>', $depth) . str_repeat('</a>', $depth) . '</kml>';

        // Either rejected as malformed/oversized, or parses within default
        // libxml limits without crashing the process; either way this must
        // not hang or exhaust memory (LIBXML_PARSEHUGE is never enabled).
        try {
            $this->parser->parse($xml);
        } catch (KmlParseException $e) {
            $this->assertTrue(true);
            return;
        }
        $this->assertTrue(true);
    }
}
