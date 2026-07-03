<?php

declare(strict_types=1);

namespace CircuitMap\Services\Geocoding;

/**
 * Calls a Nominatim-compatible geocoding search endpoint over plain HTTP
 * (via file_get_contents + a stream context, since ext-curl isn't compiled
 * into the PHP image and no other outbound-HTTP dependency exists in this
 * codebase). Every failure mode - DNS/timeout, non-2xx, malformed JSON, no
 * match - collapses to null: "geocoding unavailable" is an expected
 * fallback path (the caller lets the user place the pin manually), not an
 * application error.
 */
final class NominatimGeocodingService implements GeocodingServiceInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $userAgent,
        private readonly int $timeoutSeconds = 5
    ) {
    }

    public function geocode(string $address): ?array
    {
        $trimmed = trim($address);
        if ($trimmed === '') {
            return null;
        }

        $url = $this->baseUrl . '?' . http_build_query([
            'q' => $trimmed,
            'format' => 'jsonv2',
            'limit' => 1,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: {$this->userAgent}\r\nAccept: application/json\r\n",
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            return null;
        }

        $lat = $decoded[0]['lat'] ?? null;
        $lon = $decoded[0]['lon'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return null;
        }

        return ['latitude' => (float) $lat, 'longitude' => (float) $lon];
    }
}
