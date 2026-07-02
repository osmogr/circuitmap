<?php

declare(strict_types=1);

namespace CircuitMap\Services\Kml;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * KML placemark descriptions may contain HTML/CDATA that gets rendered as
 * an HTML popup by KML viewers. Uploaded content is untrusted, so this
 * runs every description through an allow-list HTML sanitizer before it
 * is stored or displayed. A hand-rolled tag stripper is deliberately not
 * used here; that approach is a well-known source of filter bypasses.
 */
final class KmlSanitizer
{
    private readonly HTMLPurifier $purifier;

    public function __construct()
    {
        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,a[href],span,div,table,tr,td,th,thead,tbody');
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
        $config->set('Cache.DefinitionImpl', null);
        $this->purifier = new HTMLPurifier($config);
    }

    public function sanitize(DOMDocument $dom): void
    {
        foreach ($dom->getElementsByTagName('description') as $description) {
            if (!$description instanceof DOMElement) {
                continue;
            }

            $clean = $this->purifier->purify((string) $description->textContent);

            while ($description->firstChild !== null) {
                $description->removeChild($description->firstChild);
            }
            $description->appendChild(new DOMCdataSection($clean));
        }
    }
}
