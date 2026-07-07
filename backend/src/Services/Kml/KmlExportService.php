<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\StatusColor;
use DOMDocument;
use DOMElement;
use DOMNode;
use ZipArchive;

/**
 * Builds a single KML (or KMZ) document containing every visible circuit,
 * one <Folder> per circuit. Placemark geometry is copied verbatim from each
 * circuit's stored current.kml via DOMDocument::importNode rather than
 * round-tripping through GeoJSON, which would drop altitude and styling.
 * Stored files are already validated and sanitized at upload/edit time.
 */
final class KmlExportService
{
    private const STATUSES = ['up', 'degraded', 'down', 'unknown'];

    public function __construct(
        private readonly CircuitRepository $circuits,
        private readonly FileStorageService $storage,
        private readonly KmlParser $parser
    ) {
    }

    /**
     * @return array{kml: string, exported: int, skipped: array<int, string>}
     */
    public function buildKml(): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $kml = $dom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $dom->appendChild($kml);
        $document = $dom->createElement('Document');
        $kml->appendChild($document);
        $document->appendChild($this->textElement($dom, 'name', 'CircuitMap export ' . gmdate('Y-m-d')));

        foreach (self::STATUSES as $status) {
            $document->appendChild($this->statusStyle($dom, $status));
        }

        $exported = 0;
        $skipped = [];
        foreach ($this->circuits->listVisible() as $circuit) {
            try {
                $sourceDom = $this->parser->parse($this->storage->read((string) $circuit['uuid']));
            } catch (\Throwable) {
                $skipped[] = (string) $circuit['name'];
                continue;
            }
            $document->appendChild($this->circuitFolder($dom, $circuit, $sourceDom));
            $exported++;
        }

        return ['kml' => (string) $dom->saveXML(), 'exported' => $exported, 'skipped' => $skipped];
    }

    /**
     * @return array{kmz: string, exported: int, skipped: array<int, string>}
     */
    public function buildKmz(): array
    {
        $result = $this->buildKml();

        $tempPath = tempnam(sys_get_temp_dir(), 'circuitmap_export_');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create a temporary file for KMZ export.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempPath, ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create KMZ archive.');
            }
            $zip->addFromString('doc.kml', $result['kml']);
            $zip->close();

            $bytes = file_get_contents($tempPath);
            if ($bytes === false) {
                throw new \RuntimeException('Could not read KMZ archive.');
            }
        } finally {
            @unlink($tempPath);
        }

        return ['kmz' => $bytes, 'exported' => $result['exported'], 'skipped' => $result['skipped']];
    }

    /**
     * @param array<string, mixed> $circuit
     */
    private function circuitFolder(DOMDocument $dom, array $circuit, DOMDocument $sourceDom): DOMElement
    {
        $folder = $dom->createElement('Folder');
        $folder->appendChild($this->textElement($dom, 'name', (string) $circuit['name']));

        $descEl = $dom->createElement('description');
        $descEl->appendChild($dom->createCDATASection($this->metadataHtml($circuit)));
        $folder->appendChild($descEl);

        $extended = $dom->createElement('ExtendedData');
        foreach ($this->metadataFields($circuit) as $label => $value) {
            $data = $dom->createElement('Data');
            $data->setAttribute('name', $label);
            $data->appendChild($this->textElement($dom, 'value', $value));
            $extended->appendChild($data);
        }
        $folder->appendChild($extended);

        $status = (string) ($circuit['status'] ?? 'unknown');
        $styleUrl = '#status-' . (in_array($status, self::STATUSES, true) ? $status : 'unknown');

        foreach ($sourceDom->getElementsByTagName('Placemark') as $placemark) {
            $imported = $dom->importNode($placemark, true);
            if (!$imported instanceof DOMElement) {
                continue;
            }
            // Drop any upload-era styling so the shared status style wins.
            foreach (iterator_to_array($imported->childNodes) as $child) {
                if ($child instanceof DOMElement && in_array($child->localName, ['styleUrl', 'Style'], true)) {
                    $imported->removeChild($child);
                }
            }
            // KML element order requires styleUrl before the geometry.
            $imported->insertBefore($this->textElement($dom, 'styleUrl', $styleUrl), $this->firstGeometryChild($imported));
            $folder->appendChild($imported);
        }

        return $folder;
    }

    /**
     * Metadata as label => value, empty values omitted.
     *
     * @param array<string, mixed> $circuit
     * @return array<string, string>
     */
    private function metadataFields(array $circuit): array
    {
        $fields = [
            'Provider' => (string) ($circuit['provider_name'] ?? ''),
            'Provider Circuit ID' => (string) ($circuit['provider_circuit_id'] ?? ''),
            'Status' => (string) ($circuit['status'] ?? ''),
            'Order Number' => (string) ($circuit['order_number'] ?? ''),
            'Redundant' => ((int) ($circuit['redundant'] ?? 0)) === 1 ? 'Yes' : 'No',
            'A Location' => (string) ($circuit['a_location_name'] ?? ''),
            'Z Location' => (string) ($circuit['z_location_name'] ?? ''),
            'Description' => (string) ($circuit['description'] ?? ''),
            'Tags' => (string) ($circuit['tags'] ?? ''),
        ];
        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    /**
     * CDATA description content renders as HTML in Google Earth balloons,
     * so every user-supplied value must be escaped.
     *
     * @param array<string, mixed> $circuit
     */
    private function metadataHtml(array $circuit): string
    {
        $rows = '';
        foreach ($this->metadataFields($circuit) as $label => $value) {
            $rows .= '<tr><th align="left">' . htmlspecialchars($label, ENT_QUOTES) . '</th>'
                . '<td>' . htmlspecialchars($value, ENT_QUOTES) . '</td></tr>';
        }
        return $rows === '' ? '' : '<table>' . $rows . '</table>';
    }

    private function statusStyle(DOMDocument $dom, string $status): DOMElement
    {
        $lineColor = $this->kmlColor(StatusColor::forStatus($status));

        $style = $dom->createElement('Style');
        $style->setAttribute('id', 'status-' . $status);

        $lineStyle = $dom->createElement('LineStyle');
        $lineStyle->appendChild($this->textElement($dom, 'color', $lineColor));
        $lineStyle->appendChild($this->textElement($dom, 'width', '4'));
        $style->appendChild($lineStyle);

        $polyStyle = $dom->createElement('PolyStyle');
        $polyStyle->appendChild($this->textElement($dom, 'color', '7f' . substr($lineColor, 2)));
        $style->appendChild($polyStyle);

        $iconStyle = $dom->createElement('IconStyle');
        $iconStyle->appendChild($this->textElement($dom, 'color', $lineColor));
        $style->appendChild($iconStyle);

        return $style;
    }

    /**
     * KML colors are aabbggrr, e.g. CSS '#16a34a' becomes 'ff4aa316'.
     */
    private function kmlColor(string $cssHex): string
    {
        $hex = strtolower(ltrim($cssHex, '#'));
        return 'ff' . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2);
    }

    private function firstGeometryChild(DOMElement $placemark): ?DOMNode
    {
        foreach ($placemark->childNodes as $node) {
            if ($node instanceof DOMElement
                && in_array($node->localName, ['Point', 'LineString', 'Polygon', 'MultiGeometry'], true)
            ) {
                return $node;
            }
        }
        return null;
    }

    private function textElement(DOMDocument $dom, string $name, string $text): DOMElement
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($text));
        return $el;
    }
}
