<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

use CircuitMap\Models\CircuitRepository;

/**
 * One polling pass: read host state + traffic for every Cacti-mapped
 * circuit and store the results on the circuit rows. For mapped circuits
 * Cacti is authoritative — a successful poll overwrites status regardless
 * of status_source, so a manual override lasts at most one poll interval.
 *
 * When Cacti itself is unreachable, nothing is overwritten immediately:
 * cacti-sourced statuses only flip to 'unknown' once they are older than
 * $staleAfterSeconds (i.e. a few consecutive missed polls). One missed
 * poll changes nothing; a dead Cacti cannot leave circuits green forever.
 */
final class CactiPollService
{
    public function __construct(
        private readonly CircuitRepository $circuits,
        private readonly CactiClientInterface $cacti,
        private readonly int $staleAfterSeconds = 900
    ) {
    }

    /**
     * @return array{ok: bool, circuits: int, statuses: int, usages: int, stale: int, error: ?string}
     */
    public function poll(): array
    {
        $mapped = $this->circuits->listCactiMapped();
        if ($mapped === []) {
            return ['ok' => true, 'circuits' => 0, 'statuses' => 0, 'usages' => 0, 'stale' => 0, 'error' => null];
        }

        $hostIds = array_map(static fn (array $c) => (int) $c['cacti_host_id'], $mapped);
        $localDataIds = [];
        foreach ($mapped as $circuit) {
            if ($circuit['cacti_local_data_id'] !== null) {
                $localDataIds[] = (int) $circuit['cacti_local_data_id'];
            }
        }

        try {
            $hostStatuses = $this->cacti->getHostStatuses($hostIds);
            $trafficRates = $localDataIds === [] ? [] : $this->cacti->getTrafficRates($localDataIds);
        } catch (CactiUnavailableException $e) {
            $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $this->staleAfterSeconds);
            $stale = $this->circuits->markStaleCactiStatusesUnknown($cutoff);
            return [
                'ok' => false,
                'circuits' => count($mapped),
                'statuses' => 0,
                'usages' => 0,
                'stale' => $stale,
                'error' => $e->getMessage(),
            ];
        }

        $statuses = 0;
        $usages = 0;
        foreach ($mapped as $circuit) {
            $id = (int) $circuit['id'];

            $status = CactiStatusMapper::toCircuitStatus($hostStatuses[(int) $circuit['cacti_host_id']] ?? null);
            // Written on every successful poll (not just on change): the
            // status_updated_at bump is the freshness clock that keeps the
            // circuit out of markStaleCactiStatusesUnknown's reach.
            $this->circuits->updateStatusFromPoller($id, $status);
            $statuses++;

            if ($circuit['cacti_local_data_id'] !== null) {
                // A mapping with no DSStats row (feature disabled, bad id)
                // clears the stored values — never show old traffic numbers
                // as if they were current.
                $rates = $trafficRates[(int) $circuit['cacti_local_data_id']]
                    ?? ['in_bps' => null, 'out_bps' => null];
                $this->circuits->updateUsage($id, $rates['in_bps'], $rates['out_bps']);
                $usages++;
            }
        }

        return [
            'ok' => true,
            'circuits' => count($mapped),
            'statuses' => $statuses,
            'usages' => $usages,
            'stale' => 0,
            'error' => null,
        ];
    }
}
