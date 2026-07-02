<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use PHPUnit\Framework\TestCase;

final class KmlSanitizerTest extends TestCase
{
    private KmlParser $parser;
    private KmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->parser = new KmlParser();
        $this->sanitizer = new KmlSanitizer();
    }

    private function descriptionAfterSanitizing(string $rawDescription): string
    {
        $xml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document>'
            . '<Placemark><description><![CDATA[' . $rawDescription . ']]></description>'
            . '<Point><coordinates>0,0</coordinates></Point></Placemark>'
            . '</Document></kml>';

        $dom = $this->parser->parse($xml);
        $this->sanitizer->sanitize($dom);

        $descriptions = $dom->getElementsByTagName('description');
        return $descriptions->item(0)->textContent;
    }

    public function testScriptTagIsRemoved(): void
    {
        $result = $this->descriptionAfterSanitizing('Note <script>alert(1)</script> end.');
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringNotContainsString('alert(1)', $result);
    }

    public function testOnErrorAttributeIsRemoved(): void
    {
        $result = $this->descriptionAfterSanitizing('<img src="x" onerror="alert(1)">');
        $this->assertStringNotContainsString('onerror', $result);
    }

    public function testJavascriptHrefIsNeutralized(): void
    {
        $result = $this->descriptionAfterSanitizing('<a href="javascript:alert(1)">click</a>');
        $this->assertStringNotContainsString('javascript:', $result);
    }

    public function testBenignFormattingSurvives(): void
    {
        $result = $this->descriptionAfterSanitizing('Plain text with <b>bold</b> and <br> a break.');
        $this->assertStringContainsString('bold', $result);
        $this->assertStringContainsString('<b>', $result);
    }

    public function testOutputIsSafeToEchoUnescaped(): void
    {
        $result = $this->descriptionAfterSanitizing('<script>document.cookie</script>Safe text');
        // Simulates the exact usage pattern in map.js (innerHTML on the
        // already-sanitized value); must not contain any executable markup.
        $this->assertStringNotContainsString('<script', $result);
        $this->assertStringContainsString('Safe text', $result);
    }
}
