<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Support;

use CircuitMap\Services\Cacti\CactiClientInterface;
use CircuitMap\Services\Cacti\CactiUnavailableException;

/**
 * Configurable test double for CactiClientInterface - avoids a real MySQL
 * server in tests. Populate $hostStatuses / $trafficRates with the exact
 * shapes the interface documents, or set $unavailable to make every call
 * throw CactiUnavailableException (simulating a dead Cacti DB).
 */
final class FakeCactiClient implements CactiClientInterface
{
    public bool $unavailable = false;
    public int $hostStatusCalls = 0;
    public int $trafficRateCalls = 0;

    /**
     * @param array<int, array{status: int, disabled: bool}> $hostStatuses
     * @param array<int, array{in_bps: ?int, out_bps: ?int}> $trafficRates
     */
    public function __construct(
        public array $hostStatuses = [],
        public array $trafficRates = []
    ) {
    }

    public function getHostStatuses(array $hostIds): array
    {
        $this->hostStatusCalls++;
        if ($this->unavailable) {
            throw new CactiUnavailableException('fake: cacti db unavailable');
        }
        return array_intersect_key($this->hostStatuses, array_flip($hostIds));
    }

    public function getTrafficRates(array $localDataIds): array
    {
        $this->trafficRateCalls++;
        if ($this->unavailable) {
            throw new CactiUnavailableException('fake: cacti db unavailable');
        }
        return array_intersect_key($this->trafficRates, array_flip($localDataIds));
    }
}
