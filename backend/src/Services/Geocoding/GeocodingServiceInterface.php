<?php

declare(strict_types=1);

namespace CircuitMap\Services\Geocoding;

/**
 * Contract for turning a free-text address into coordinates. The only
 * adapter shipped is NominatimGeocodingService (OpenStreetMap's public
 * geocoder over HTTP); a future adapter (Google/Mapbox/self-hosted
 * Nominatim) would implement this interface and be swapped in wherever
 * NominatimGeocodingService is constructed today, without touching
 * LocationController.
 */
interface GeocodingServiceInterface
{
    /**
     * @return array{latitude: float, longitude: float}|null null means "no
     *   match or service unavailable" - callers must treat both the same
     *   way (fall back to manual map placement), not throw.
     */
    public function geocode(string $address): ?array;
}
