<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Services\Kml\KmlFolderSplitter;
use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlValidator;
use PHPUnit\Framework\TestCase;

final class KmlFolderSplitterTest extends TestCase
{
    private KmlParser $parser;
    private KmlFolderSplitter $splitter;

    protected function setUp(): void
    {
        $this->parser = new KmlParser();
        $this->splitter = new KmlFolderSplitter();
    }

    private function parseFixture(string $name): \DOMDocument
    {
        return $this->parser->parse((string) file_get_contents(__DIR__ . '/../../fixtures/' . $name));
    }

    public function testEnumerateListsFoldersInDocumentOrderWithUngroupedLast(): void
    {
        $candidates = $this->splitter->enumerate($this->parseFixture('multi_folder.kml'));

        self::assertSame([
            ['key' => '0', 'name' => 'Circuit Alpha', 'placemarkCount' => 3],
            ['key' => '1', 'name' => 'Circuit Beta', 'placemarkCount' => 1],
            ['key' => '2', 'name' => 'Empty Folder', 'placemarkCount' => 0],
            ['key' => KmlFolderSplitter::UNGROUPED_KEY, 'name' => '', 'placemarkCount' => 1],
        ], $candidates);
    }

    public function testMultiFolderDocumentIsSplittable(): void
    {
        self::assertTrue($this->splitter->isSplittable($this->parseFixture('multi_folder.kml')));
    }

    public function testFolderlessDocumentIsNotSplittable(): void
    {
        self::assertFalse($this->splitter->isSplittable($this->parseFixture('valid_simple.kml')));
    }

    public function testSingleFolderDocumentIsNotSplittable(): void
    {
        self::assertFalse($this->splitter->isSplittable($this->parseFixture('single_folder.kml')));
    }

    public function testExtractFolderKeepsNestedContentAndSharedStyles(): void
    {
        $extracted = $this->splitter->extract($this->parseFixture('multi_folder.kml'), '0');

        self::assertSame(3, $extracted->getElementsByTagName('Placemark')->length);
        self::assertSame(1, $extracted->getElementsByTagName('Folder')->length, 'nested subfolder travels along');

        $styles = $extracted->getElementsByTagName('Style');
        self::assertSame(1, $styles->length, 'shared document-level style is copied');
        self::assertSame('line-red', $styles->item(0)->getAttribute('id'));

        // The folder's own <name> must not leak into the new Document.
        $documentNames = [];
        foreach ($extracted->documentElement->firstChild->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'name') {
                $documentNames[] = $child->textContent;
            }
        }
        self::assertSame([], $documentNames);

        (new KmlValidator())->validate($extracted);
        $this->addToAssertionCount(1);
    }

    public function testExtractedDocumentSurvivesReparse(): void
    {
        $extracted = $this->splitter->extract($this->parseFixture('multi_folder.kml'), '1');
        $reparsed = $this->parser->parse((string) $extracted->saveXML());

        self::assertSame(1, $reparsed->getElementsByTagName('Placemark')->length);
        self::assertStringContainsString('Beta Segment 1', (string) $extracted->saveXML());
    }

    public function testExtractUngroupedContainsOnlyLoosePlacemarks(): void
    {
        $extracted = $this->splitter->extract(
            $this->parseFixture('multi_folder.kml'),
            KmlFolderSplitter::UNGROUPED_KEY
        );

        self::assertSame(1, $extracted->getElementsByTagName('Placemark')->length);
        self::assertStringContainsString('Loose Handhole', (string) $extracted->saveXML());
        self::assertSame(0, $extracted->getElementsByTagName('Folder')->length);
    }

    public function testExtractUnknownKeyIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->splitter->extract($this->parseFixture('multi_folder.kml'), '99');
    }

    public function testExtractNonNumericKeyIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->splitter->extract($this->parseFixture('multi_folder.kml'), '../etc');
    }

    public function testExtractEmptyFolderIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/no placemarks/');
        $this->splitter->extract($this->parseFixture('multi_folder.kml'), '2');
    }

    public function testExtractUngroupedWithoutLoosePlacemarksIsRejected(): void
    {
        $this->expectException(KmlParseException::class);
        $this->splitter->extract(
            $this->parseFixture('single_folder.kml'),
            KmlFolderSplitter::UNGROUPED_KEY
        );
    }
}
