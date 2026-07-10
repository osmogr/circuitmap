<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;

/**
 * One polling pass: read device state for every Cacti-mapped location and
 * traffic for every Cacti-mapped circuit, and store the results on the
 * respective rows. Cacti drives *site* (location) status only; circuit
 * status is manual and never touched by the poller.
 *
 * When Cacti itself is unreachable, nothing is overwritten immediately:
 * location statuses only flip to 'unknown' once they are older than
 * $staleAfterSeconds (i.e. a few consecutive missed polls). One missed
 * poll changes nothing; a dead Cacti cannot leave sites green forever.
 */
final class CactiPollService
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly CircuitRepository $circuits,
        private readonly CactiClientInterface $cacti,
        private readonly int $staleAfterSeconds = 900
    ) {
    }

    /**
     * @return array{ok: bool, locations: int, statuses: int, circuits: int, usages: int, stale: int, error: ?string}
     */
    public function poll(): array
    {
        $mappedLocations = $this->locations->listCactiMapped();
        $trafficCircuits = $this->circuits->listTrafficMapped();
        if ($mappedLocations === [] && $trafficCircuits === []) {
            return [
                'ok' => true,
                'locations' => 0,
                'statuses' => 0,
                'circuits' => 0,
                'usages' => 0,
                'stale' => 0,
                'error' => null,
            ];
        }

        $hostIds = array_map(static fn (array $l) => (int) $l['cacti_host_id'], $mappedLocations);
        $localDataIds = array_map(static fn (array $c) => (int) $c['cacti_local_data_id'], $trafficCircuits);

        try {
            $hostStatuses = $hostIds === [] ? [] : $this->cacti->getHostStatuses($hostIds);
            $trafficRates = $localDataIds === [] ? [] : $this->cacti->getTrafficRates($localDataIds);
        } catch (CactiUnavailableException $e) {
            $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $this->staleAfterSeconds);
            $stale = $this->locations->markStaleStatusesUnknown($cutoff);
            return [
                'ok' => false,
                'locations' => count($mappedLocations),
                'statuses' => 0,
                'circuits' => count($trafficCircuits),
                'usages' => 0,
                'stale' => $stale,
                'error' => $e->getMessage(),
            ];
        }

        $statuses = 0;
        foreach ($mappedLocations as $location) {
            $status = CactiStatusMapper::toStatus($hostStatuses[(int) $location['cacti_host_id']] ?? null);
            // Written on every successful poll (not just on change): the
            // status_updated_at bump is the freshness clock that keeps the
            // location out of markStaleStatusesUnknown's reach.
            $this->locations->updateStatusFromPoller((int) $location['id'], $status);
            $statuses++;
        }

        $usages = 0;
        foreach ($trafficCircuits as $circuit) {
            // A mapping with no DSStats row (feature disabled, bad id)
            // clears the stored values — never show old traffic numbers
            // as if they were current.
            $rates = $trafficRates[(int) $circuit['cacti_local_data_id']]
                ?? ['in_bps' => null, 'out_bps' => null];
            $this->circuits->updateUsage((int) $circuit['id'], $rates['in_bps'], $rates['out_bps']);
            $usages++;
        }

        return [
            'ok' => true,
            'locations' => count($mappedLocations),
            'statuses' => $statuses,
            'circuits' => count($trafficCircuits),
            'usages' => $usages,
            'stale' => 0,
            'error' => null,
        ];
    }
}
