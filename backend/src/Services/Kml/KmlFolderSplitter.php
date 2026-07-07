<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Splits a multi-folder KML document into one standalone KML document per
 * top-level <Folder> (the inverse of KmlExportService, which writes one
 * <Folder> per circuit). Candidates are the top-level folders plus one
 * "ungrouped" bucket for placemarks that sit outside any folder. Candidate
 * keys are ordinals in document order ("0", "1", ...) or UNGROUPED_KEY;
 * they are only stable as long as the source document is unchanged, so
 * callers must enumerate and extract from the same stored bytes.
 */
final class KmlFolderSplitter
{
    public const UNGROUPED_KEY = 'ungrouped';

    /**
     * Candidates in document order, the ungrouped bucket last (omitted when
     * there are no loose placemarks). Folders are listed even when empty so
     * the UI can show them; extract() rejects empty candidates.
     *
     * @return array<int, array{key: string, name: string, placemarkCount: int}>
     */
    public function enumerate(DOMDocument $dom): array
    {
        $candidates = [];
        foreach ($this->topLevelFolders($dom) as $ordinal => $folder) {
            $candidates[] = [
                'key' => (string) $ordinal,
                'name' => $this->folderName($folder),
                'placemarkCount' => $folder->getElementsByTagName('Placemark')->length,
            ];
        }

        $looseCount = count($this->loosePlacemarks($dom));
        if ($looseCount > 0) {
            $candidates[] = [
                'key' => self::UNGROUPED_KEY,
                'name' => '',
                'placemarkCount' => $looseCount,
            ];
        }

        return $candidates;
    }

    public function isSplittable(DOMDocument $dom): bool
    {
        $nonEmpty = 0;
        foreach ($this->enumerate($dom) as $candidate) {
            if ($candidate['placemarkCount'] > 0) {
                $nonEmpty++;
            }
        }
        return $nonEmpty >= 2;
    }

    /**
     * Builds a standalone <kml><Document> for one candidate: shared
     * document-level styles first, then the candidate's content. A folder's
     * children are copied directly into the new Document (minus the folder's
     * own <name>, which becomes the circuit name) so the result looks like a
     * plain single-circuit upload.
     */
    public function extract(DOMDocument $dom, string $key): DOMDocument
    {
        $newDom = new DOMDocument('1.0', 'UTF-8');
        $newDom->formatOutput = true;
        $kml = $newDom->createElementNS('http://www.opengis.net/kml/2.2', 'kml');
        $newDom->appendChild($kml);
        $document = $newDom->createElement('Document');
        $kml->appendChild($document);

        foreach ($this->sharedStyles($dom) as $style) {
            $document->appendChild($newDom->importNode($style, true));
        }

        if ($key === self::UNGROUPED_KEY) {
            $placemarks = $this->loosePlacemarks($dom);
            if (count($placemarks) === 0) {
                throw new KmlParseException('The document has no placemarks outside of folders.');
            }
            foreach ($placemarks as $placemark) {
                $document->appendChild($newDom->importNode($placemark, true));
            }
            return $newDom;
        }

        $folders = $this->topLevelFolders($dom);
        if (preg_match('/^\d+$/', $key) !== 1 || !isset($folders[(int) $key])) {
            throw new KmlParseException('Unknown folder selection.');
        }

        $folder = $folders[(int) $key];
        if ($folder->getElementsByTagName('Placemark')->length === 0) {
            throw new KmlParseException('The selected folder contains no placemarks.');
        }

        foreach ($folder->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'name') {
                continue;
            }
            $document->appendChild($newDom->importNode($child, true));
        }

        return $newDom;
    }

    /**
     * Top-level folders (no <Folder> ancestor) in document order, indexed
     * by ordinal — the ordinal is the candidate key contract.
     *
     * @return array<int, DOMElement>
     */
    private function topLevelFolders(DOMDocument $dom): array
    {
        $folders = [];
        foreach ($dom->getElementsByTagName('Folder') as $folder) {
            if ($folder instanceof DOMElement && !$this->hasFolderAncestor($folder)) {
                $folders[] = $folder;
            }
        }
        return $folders;
    }

    /**
     * @return array<int, DOMElement>
     */
    private function loosePlacemarks(DOMDocument $dom): array
    {
        $placemarks = [];
        foreach ($dom->getElementsByTagName('Placemark') as $placemark) {
            if ($placemark instanceof DOMElement && !$this->hasFolderAncestor($placemark)) {
                $placemarks[] = $placemark;
            }
        }
        return $placemarks;
    }

    /**
     * Shared styles are direct children of <Document> (or of <kml> for
     * wrapper-less documents); inline styles inside placemarks or folders
     * travel with their owner via the deep import instead.
     *
     * @return array<int, DOMElement>
     */
    private function sharedStyles(DOMDocument $dom): array
    {
        $styles = [];
        foreach (['Style', 'StyleMap'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $style) {
                if (!$style instanceof DOMElement) {
                    continue;
                }
                $parent = $style->parentNode;
                if ($parent instanceof DOMElement && in_array($parent->localName, ['Document', 'kml'], true)) {
                    $styles[] = $style;
                }
            }
        }
        return $styles;
    }

    private function hasFolderAncestor(DOMElement $element): bool
    {
        for ($node = $element->parentNode; $node instanceof DOMNode; $node = $node->parentNode) {
            if ($node instanceof DOMElement && $node->localName === 'Folder') {
                return true;
            }
        }
        return false;
    }

    private function folderName(DOMElement $folder): string
    {
        foreach ($folder->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'name') {
                return trim((string) $child->textContent);
            }
        }
        return '';
    }
}
