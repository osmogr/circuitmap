<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Kml\KmlExportService;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\Uuid;
use CircuitMap\Tests\Support\DatabaseTestCase;
use DOMDocument;
use DOMElement;
use ZipArchive;

final class KmlExportServiceTest extends DatabaseTestCase
{
    private const NAMESPACED_KML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<kml xmlns="http://www.opengis.net/kml/2.2"><Document><Placemark>'
        . '<name>Alpha segment</name>'
        . '<styleUrl>#old-style</styleUrl>'
        . '<Style><LineStyle><color>ff0000ff</color></LineStyle></Style>'
        . '<LineString><coordinates>-122.1,47.6 -122.2,47.7</coordinates></LineString>'
        . '</Placemark></Document></kml>';

    private const PLAIN_KML = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<kml><Document><Placemark>'
        . '<Point><coordinates>-122.3,47.5</coordinates></Point>'
        . '</Placemark></Document></kml>';

    private KmlExportService $service;
    private CircuitRepository $circuits;
    private FileStorageService $storage;
    private int $ownerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerId = $this->createUser('owner');
        $this->circuits = new CircuitRepository($this->pdo);
        $this->storage = new FileStorageService($this->storagePath);
        $this->service = new KmlExportService($this->circuits, $this->storage, new KmlParser());
    }

    private function createCircuit(string $name, string $kml, array $extra = []): string
    {
        $uuid = Uuid::v4();
        $this->storage->saveNew($uuid, $kml);
        $id = $this->circuits->insert(
            $uuid,
            $name,
            $extra['description'] ?? null,
            $extra['tags'] ?? null,
            $this->ownerId,
            $this->storage->relativePath($uuid),
            $extra['providerId'] ?? null,
            $extra['providerCircuitId'] ?? null,
            $extra['orderNumber'] ?? null,
            $extra['redundant'] ?? false,
            $extra['aLocationId'] ?? null,
            $extra['zLocationId'] ?? null
        );
        if (isset($extra['status'])) {
            $this->circuits->updateStatus($id, $extra['status'], 'manual');
        }
        return $uuid;
    }

    private function createProvider(string $name): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO circuit_providers (name, created_at, updated_at) VALUES (:name, :now, :now)'
        );
        $stmt->execute(['name' => $name, 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createLocation(string $name): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->pdo->prepare(
            'INSERT INTO locations (name, created_at, updated_at) VALUES (:name, :now, :now)'
        );
        $stmt->execute(['name' => $name, 'now' => $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int, DOMElement>
     */
    private function folders(DOMDocument $dom): array
    {
        return iterator_to_array($dom->getElementsByTagName('Folder'));
    }

    private function directChild(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                return $node;
            }
        }
        return null;
    }

    public function testExportMergesNamespacedAndPlainCircuitsIntoFolders(): void
    {
        $this->createCircuit('Alpha', self::NAMESPACED_KML, ['status' => 'down']);
        $this->createCircuit('Bravo', self::PLAIN_KML);

        $result = $this->service->buildKml();

        $this->assertSame(2, $result['exported']);
        $this->assertSame([], $result['skipped']);

        // Output must survive the app's own hardened parser.
        $dom = (new KmlParser())->parse($result['kml']);

        $folders = $this->folders($dom);
        $this->assertCount(2, $folders);
        $this->assertSame('Alpha', $this->directChild($folders[0], 'name')->textContent);
        $this->assertSame('Bravo', $this->directChild($folders[1], 'name')->textContent);

        $alphaPlacemark = $folders[0]->getElementsByTagName('Placemark')->item(0);
        $this->assertInstanceOf(DOMElement::class, $alphaPlacemark);
        $this->assertSame('#status-down', $this->directChild($alphaPlacemark, 'styleUrl')->textContent);
        $this->assertNull($this->directChild($alphaPlacemark, 'Style'));
        $this->assertStringContainsString('-122.1,47.6', $alphaPlacemark->textContent);

        $bravoPlacemark = $folders[1]->getElementsByTagName('Placemark')->item(0);
        $this->assertSame('#status-unknown', $this->directChild($bravoPlacemark, 'styleUrl')->textContent);
    }

    public function testStatusStyleUsesKmlByteOrder(): void
    {
        $this->createCircuit('Alpha', self::NAMESPACED_KML, ['status' => 'down']);

        $dom = (new KmlParser())->parse($this->service->buildKml()['kml']);

        $downStyle = null;
        foreach ($dom->getElementsByTagName('Style') as $style) {
            if ($style->getAttribute('id') === 'status-down') {
                $downStyle = $style;
            }
        }
        $this->assertInstanceOf(DOMElement::class, $downStyle);
        // StatusColor 'down' is #dc2626; KML color order is aabbggrr.
        $lineColor = $downStyle->getElementsByTagName('LineStyle')->item(0)
            ->getElementsByTagName('color')->item(0)->textContent;
        $this->assertSame('ff2626dc', $lineColor);
    }

    public function testFolderDescriptionAndExtendedDataCarryMetadata(): void
    {
        $providerId = $this->createProvider('Lumen & Co');
        $aLocationId = $this->createLocation('HQ <East>');
        $this->createCircuit('Alpha', self::NAMESPACED_KML, [
            'providerId' => $providerId,
            'providerCircuitId' => 'LUM-001',
            'orderNumber' => 'ORD-9',
            'redundant' => true,
            'aLocationId' => $aLocationId,
            'description' => 'Primary uplink',
            'tags' => 'core,fiber',
        ]);

        $result = $this->service->buildKml();
        $dom = (new KmlParser())->parse($result['kml']);
        $folder = $this->folders($dom)[0];

        $description = $this->directChild($folder, 'description')->textContent;
        $this->assertStringContainsString('Lumen &amp; Co', $description);
        $this->assertStringContainsString('HQ &lt;East&gt;', $description);
        $this->assertStringContainsString('LUM-001', $description);
        $this->assertStringContainsString('ORD-9', $description);
        $this->assertStringContainsString('Yes', $description);
        $this->assertStringContainsString('Primary uplink', $description);
        $this->assertStringContainsString('core,fiber', $description);

        $extended = $this->directChild($folder, 'ExtendedData');
        $values = [];
        foreach ($extended->getElementsByTagName('Data') as $data) {
            $values[$data->getAttribute('name')] = trim($data->textContent);
        }
        $this->assertSame('Lumen & Co', $values['Provider']);
        $this->assertSame('HQ <East>', $values['A Location']);
        $this->assertSame('Yes', $values['Redundant']);
        $this->assertArrayNotHasKey('Z Location', $values);
    }

    public function testCircuitNameIsEscapedInOutput(): void
    {
        $this->createCircuit('<script>x</script> & Co', self::PLAIN_KML);

        $result = $this->service->buildKml();
        $dom = (new KmlParser())->parse($result['kml']);

        $name = $this->directChild($this->folders($dom)[0], 'name');
        $this->assertSame('<script>x</script> & Co', $name->textContent);
        $this->assertStringNotContainsString('<name><script>', $result['kml']);
    }

    public function testCircuitWithMissingFileIsSkipped(): void
    {
        $this->createCircuit('Alpha', self::NAMESPACED_KML);
        $ghostUuid = Uuid::v4();
        $this->circuits->insert(
            $ghostUuid,
            'Ghost Circuit',
            null,
            null,
            $this->ownerId,
            "circuits/{$ghostUuid}/current.kml"
        );

        $result = $this->service->buildKml();

        $this->assertSame(1, $result['exported']);
        $this->assertSame(['Ghost Circuit'], $result['skipped']);
        $this->assertCount(1, $this->folders((new KmlParser())->parse($result['kml'])));
    }

    public function testZeroCircuitsStillProducesValidKml(): void
    {
        $result = $this->service->buildKml();

        $this->assertSame(0, $result['exported']);
        $dom = (new KmlParser())->parse($result['kml']);
        $this->assertCount(0, $this->folders($dom));
        $this->assertSame('http://www.opengis.net/kml/2.2', $dom->documentElement->namespaceURI);
    }

    public function testSoftDeletedCircuitsAreExcluded(): void
    {
        $this->createCircuit('Alpha', self::NAMESPACED_KML);
        $deletedUuid = $this->createCircuit('Deleted', self::PLAIN_KML);
        $deleted = $this->circuits->findByUuid($deletedUuid);
        $this->circuits->softDelete((int) $deleted['id']);

        $result = $this->service->buildKml();

        $this->assertSame(1, $result['exported']);
        $this->assertStringNotContainsString('Deleted', $result['kml']);
    }

    public function testKmzContainsDocKmlMatchingKmlOutput(): void
    {
        $this->createCircuit('Alpha', self::NAMESPACED_KML);

        $result = $this->service->buildKmz();

        $this->assertStringStartsWith('PK', $result['kmz']);
        $this->assertSame(1, $result['exported']);

        $tempPath = tempnam(sys_get_temp_dir(), 'circuitmap_test_kmz_');
        try {
            file_put_contents($tempPath, $result['kmz']);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($tempPath));
            $docKml = $zip->getFromName('doc.kml');
            $zip->close();
        } finally {
            @unlink($tempPath);
        }

        $this->assertIsString($docKml);
        $this->assertSame($this->service->buildKml()['kml'], $docKml);
    }
}
