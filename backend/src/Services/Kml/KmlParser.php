<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use DOMDocument;

/**
 * Hardened KML/XML parser. This is the single place user-uploaded KML text
 * is turned into a DOM. Read the comments below before changing any flag
 * here; each one is a deliberate security decision, not an oversight.
 *
 * XXE posture on PHP 8.2 / bundled libxml2 (>= 2.9.0):
 *
 * - libxml_disable_entity_loader() is intentionally NOT called. It has
 *   been a no-op deprecated function since PHP 8.0, because libxml2 has
 *   disabled external entity loading by default since 2.9.0 regardless of
 *   that PHP-level toggle. Calling it only produces a deprecation notice
 *   and does nothing. Do not "fix" this by adding it back.
 * - The actual defense is flag hygiene on DOMDocument::loadXML():
 *     - LIBXML_NONET is always passed (defense in depth; forbids network
 *       access during parsing even though libxml2's default already blocks
 *       external entity fetches).
 *     - LIBXML_NOENT is NEVER passed. That flag is what turns on entity
 *       *substitution* (expanding declared entities into the tree); without
 *       it, libxml2 does not resolve entities into content even if a DTD
 *       declares them.
 *     - LIBXML_DTDLOAD is NEVER passed (would fetch/load external DTDs).
 *     - LIBXML_PARSEHUGE is NEVER passed. It raises libxml2's internal
 *       depth/node-size limits and is a documented resource-exhaustion
 *       vector for crafted XML; the default limits are kept as a guard.
 * - Belt and suspenders: any input containing a "<!DOCTYPE" declaration is
 *   rejected outright, both via a fast pre-parse string scan and a
 *   post-parse check of DOMDocument::doctype. KML has no legitimate use
 *   for a DTD, so this sidesteps entity-expansion concerns entirely rather
 *   than relying purely on flag correctness.
 */
final class KmlParser
{
    public function __construct(private readonly int $maxBytes = 10_485_760)
    {
    }

    public function parse(string $xml): DOMDocument
    {
        if (strlen($xml) === 0) {
            throw new KmlParseException('Empty file.');
        }

        if (strlen($xml) > $this->maxBytes) {
            throw new KmlParseException('File exceeds the maximum allowed size.');
        }

        if (preg_match('/<!DOCTYPE/i', $xml) === 1) {
            throw new KmlParseException('DOCTYPE declarations are not allowed in KML uploads.');
        }

        $previousSetting = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $dom = new DOMDocument();
        $dom->resolveExternals = false;
        $dom->substituteEntities = false;

        $loaded = $dom->loadXML($xml, LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if (!$loaded) {
            $message = $errors[0]->message ?? 'Unknown XML parse error.';
            throw new KmlParseException('Malformed XML: ' . trim($message));
        }

        if ($dom->doctype !== null) {
            throw new KmlParseException('DOCTYPE declarations are not allowed in KML uploads.');
        }

        return $dom;
    }
}
