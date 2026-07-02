<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use DOMDocument;
use DOMElement;

/**
 * Structural validation on an already-parsed (hardened) DOM. This is the
 * authoritative check for whether an upload is accepted; MIME sniffing
 * elsewhere is only a fast first-pass filter, not a substitute for this.
 */
final class KmlValidator
{
    private const GEOMETRY_TAGS = ['Point', 'LineString', 'Polygon', 'MultiGeometry'];
    private const COORD_TUPLE_PATTERN = '/^-?\d+(\.\d+)?,-?\d+(\.\d+)?(,-?\d+(\.\d+)?)?$/';

    public function validate(DOMDocument $dom): void
    {
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'kml') {
            throw new KmlParseException('Root element must be <kml>.');
        }

        $placemarks = $dom->getElementsByTagName('Placemark');
        if ($placemarks->length === 0) {
            throw new KmlParseException('KML must contain at least one Placemark.');
        }

        foreach ($placemarks as $placemark) {
            /** @var DOMElement $placemark */
            $this->validatePlacemark($placemark);
        }
    }

    private function validatePlacemark(DOMElement $placemark): void
    {
        $geometry = $this->firstChildGeometry($placemark);
        if ($geometry === null) {
            throw new KmlParseException('Placemark "' . $placemark->getAttribute('id')
                . '" has no supported geometry (Point/LineString/Polygon/MultiGeometry).');
        }

        $this->validateGeometry($geometry);
    }

    private function validateGeometry(DOMElement $geometry): void
    {
        switch ($geometry->localName) {
            case 'Point':
                $this->validateCoordinates($geometry, 1, 1);
                break;
            case 'LineString':
                $this->validateCoordinates($geometry, 2, null);
                break;
            case 'Polygon':
                $ring = $this->findDescendant($geometry, 'LinearRing');
                if ($ring === null) {
                    throw new KmlParseException('Polygon is missing an outer boundary LinearRing.');
                }
                $this->validateCoordinates($ring, 4, null);
                break;
            case 'MultiGeometry':
                $children = $this->childGeometries($geometry);
                if (count($children) === 0) {
                    throw new KmlParseException('MultiGeometry contains no supported child geometries.');
                }
                foreach ($children as $child) {
                    $this->validateGeometry($child);
                }
                break;
            default:
                throw new KmlParseException('Unsupported geometry type: ' . $geometry->localName);
        }
    }

    private function validateCoordinates(DOMElement $geometry, int $minTuples, ?int $maxTuples): void
    {
        $coordsEl = $this->findDescendant($geometry, 'coordinates');
        if ($coordsEl === null) {
            throw new KmlParseException('Geometry is missing a <coordinates> element.');
        }

        $text = trim((string) $coordsEl->textContent);
        if ($text === '') {
            throw new KmlParseException('Geometry has empty coordinates.');
        }

        $tuples = preg_split('/\s+/', $text) ?: [];
        if (count($tuples) < $minTuples || ($maxTuples !== null && count($tuples) > $maxTuples)) {
            throw new KmlParseException(sprintf(
                'Geometry has %d coordinate tuple(s), expected %s.',
                count($tuples),
                $maxTuples === null ? "at least {$minTuples}" : "between {$minTuples} and {$maxTuples}"
            ));
        }

        foreach ($tuples as $tuple) {
            if (preg_match(self::COORD_TUPLE_PATTERN, $tuple) !== 1) {
                throw new KmlParseException("Malformed coordinate tuple: \"{$tuple}\".");
            }
        }
    }

    private function firstChildGeometry(DOMElement $placemark): ?DOMElement
    {
        foreach ($placemark->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, self::GEOMETRY_TAGS, true)) {
                return $node;
            }
        }
        return null;
    }

    /**
     * @return DOMElement[]
     */
    private function childGeometries(DOMElement $multiGeometry): array
    {
        $found = [];
        foreach ($multiGeometry->childNodes as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, self::GEOMETRY_TAGS, true)) {
                $found[] = $node;
            }
        }
        return $found;
    }

    private function findDescendant(DOMElement $context, string $localName): ?DOMElement
    {
        foreach ($context->getElementsByTagName($localName) as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }
        return null;
    }
}
