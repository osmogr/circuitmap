<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use DOMDocument;
use DOMElement;

/**
 * Converts an already-validated KML DOM into a GeoJSON FeatureCollection
 * for the map frontend. KML altitude values are dropped; the map is 2D.
 */
final class GeoJsonConverter
{
    /**
     * @return array{type: string, features: array<int, array<string, mixed>>}
     */
    public function toGeoJson(DOMDocument $dom): array
    {
        $features = [];

        foreach ($dom->getElementsByTagName('Placemark') as $placemark) {
            if (!$placemark instanceof DOMElement) {
                continue;
            }

            $geometryEl = $this->firstChildGeometry($placemark);
            if ($geometryEl === null) {
                continue;
            }

            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $this->directChildText($placemark, 'name'),
                    'description' => $this->directChildText($placemark, 'description'),
                ],
                'geometry' => $this->convertGeometry($geometryEl),
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * Builds a fresh KML DOMDocument from a client-submitted GeoJSON
     * FeatureCollection (the Leaflet-Geoman editor's save payload). The
     * result still goes through KmlValidator and KmlSanitizer before
     * storage, same as an upload; this method does not sanitize.
     *
     * @param array<string, mixed> $featureCollection
     */
    public function toKml(array $featureCollection): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $kml = $dom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $dom->appendChild($kml);
        $document = $dom->createElement('Document');
        $kml->appendChild($document);

        foreach ((array) ($featureCollection['features'] ?? []) as $feature) {
            $placemark = $dom->createElement('Placemark');

            $name = (string) ($feature['properties']['name'] ?? '');
            if ($name !== '') {
                $nameEl = $dom->createElement('name');
                $nameEl->appendChild($dom->createTextNode($name));
                $placemark->appendChild($nameEl);
            }

            $description = (string) ($feature['properties']['description'] ?? '');
            if ($description !== '') {
                $descEl = $dom->createElement('description');
                $descEl->appendChild($dom->createCDATASection($description));
                $placemark->appendChild($descEl);
            }

            $geometryEl = $this->buildGeometryElement($dom, (array) ($feature['geometry'] ?? []));
            if ($geometryEl !== null) {
                $placemark->appendChild($geometryEl);
            }

            $document->appendChild($placemark);
        }

        return $dom;
    }

    /**
     * @param array<string, mixed> $geometry
     */
    private function buildGeometryElement(DOMDocument $dom, array $geometry): ?DOMElement
    {
        switch ($geometry['type'] ?? null) {
            case 'Point':
                $el = $dom->createElement('Point');
                $el->appendChild($this->coordinatesElement($dom, [(array) $geometry['coordinates']]));
                return $el;

            case 'LineString':
                $el = $dom->createElement('LineString');
                $el->appendChild($this->coordinatesElement($dom, (array) $geometry['coordinates']));
                return $el;

            case 'Polygon':
                $el = $dom->createElement('Polygon');
                foreach ((array) $geometry['coordinates'] as $i => $ring) {
                    $boundary = $dom->createElement($i === 0 ? 'outerBoundaryIs' : 'innerBoundaryIs');
                    $linearRing = $dom->createElement('LinearRing');
                    $linearRing->appendChild($this->coordinatesElement($dom, (array) $ring));
                    $boundary->appendChild($linearRing);
                    $el->appendChild($boundary);
                }
                return $el;

            case 'GeometryCollection':
                $el = $dom->createElement('MultiGeometry');
                foreach ((array) ($geometry['geometries'] ?? []) as $child) {
                    $childEl = $this->buildGeometryElement($dom, (array) $child);
                    if ($childEl !== null) {
                        $el->appendChild($childEl);
                    }
                }
                return $el;

            default:
                return null;
        }
    }

    /**
     * @param array<int, array<int, mixed>> $tuples
     */
    private function coordinatesElement(DOMDocument $dom, array $tuples): DOMElement
    {
        $text = implode(' ', array_map(
            static fn (array $t): string => (($t[0] ?? 0) . ',' . ($t[1] ?? 0)),
            $tuples
        ));
        $el = $dom->createElement('coordinates');
        $el->appendChild($dom->createTextNode($text));
        return $el;
    }

    /**
     * @return array<string, mixed>
     */
    private function convertGeometry(DOMElement $geometry): array
    {
        switch ($geometry->localName) {
            case 'Point':
                $coords = $this->parseCoordinateTuples($this->descendantText($geometry, 'coordinates'));
                return ['type' => 'Point', 'coordinates' => $coords[0] ?? [0.0, 0.0]];

            case 'LineString':
                return [
                    'type' => 'LineString',
                    'coordinates' => $this->parseCoordinateTuples($this->descendantText($geometry, 'coordinates')),
                ];

            case 'Polygon':
                $rings = [];
                foreach ($geometry->getElementsByTagName('LinearRing') as $ring) {
                    if ($ring instanceof DOMElement) {
                        $rings[] = $this->parseCoordinateTuples($this->descendantText($ring, 'coordinates'));
                    }
                }
                return ['type' => 'Polygon', 'coordinates' => $rings];

            case 'MultiGeometry':
                $geometries = [];
                foreach ($geometry->childNodes as $child) {
                    if ($child instanceof DOMElement
                        && in_array($child->localName, ['Point', 'LineString', 'Polygon', 'MultiGeometry'], true)
                    ) {
                        $geometries[] = $this->convertGeometry($child);
                    }
                }
                return ['type' => 'GeometryCollection', 'geometries' => $geometries];

            default:
                return ['type' => 'GeometryCollection', 'geometries' => []];
        }
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function parseCoordinateTuples(string $text): array
    {
        $tuples = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $raw) {
            if ($raw === '') {
                continue;
            }
            $parts = explode(',', $raw);
            $lon = (float) ($parts[0] ?? 0);
            $lat = (float) ($parts[1] ?? 0);
            $tuples[] = [$lon, $lat];
        }
        return $tuples;
    }

    private function firstChildGeometry(DOMElement $placemark): ?DOMElement
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

    private function directChildText(DOMElement $parent, string $localName): string
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                return trim($node->textContent);
            }
        }
        return '';
    }

    private function descendantText(DOMElement $context, string $localName): string
    {
        $nodes = $context->getElementsByTagName($localName);
        return $nodes->length > 0 ? trim($nodes->item(0)->textContent) : '';
    }
}
