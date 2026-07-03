<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Support;

use CircuitMap\Services\Geocoding\GeocodingServiceInterface;

/**
 * Configurable test double for GeocodingServiceInterface - avoids real
 * network calls in tests. Set $result to a coordinate pair or null before
 * exercising the code under test, and check $callCount / $lastAddress to
 * assert the service was (or wasn't) invoked.
 */
final class FakeGeocodingService implements GeocodingServiceInterface
{
    public int $callCount = 0;
    public ?string $lastAddress = null;

    /**
     * @param array{latitude: float, longitude: float}|null $result
     */
    public function __construct(private ?array $result = null)
    {
    }

    /**
     * @param array{latitude: float, longitude: float}|null $result
     */
    public function setResult(?array $result): void
    {
        $this->result = $result;
    }

    public function geocode(string $address): ?array
    {
        $this->callCount++;
        $this->lastAddress = $address;
        return $this->result;
    }
}
