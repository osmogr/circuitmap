<?php

declare(strict_types=1);

namespace CircuitMap\Services\Cacti;

/**
 * Read-only view of a Cacti 1.2.x server's MySQL database. Implementations
 * must throw CactiUnavailableException when Cacti cannot be reached or
 * queried, so CactiPollService can distinguish "Cacti is down" (statuses
 * become stale) from "this host id has no row" (status is unknown).
 */
interface CactiClientInterface
{
    /**
     * @param array<int, int> $hostIds Cacti host.id values
     * @return array<int, array{status: int, disabled: bool}> keyed by host id;
     *         host ids with no row in Cacti are simply absent from the result.
     *
     * @throws CactiUnavailableException
     */
    public function getHostStatuses(array $hostIds): array;

    /**
     * Latest polled traffic rates from Cacti's DSStats tables, in bits/sec.
     *
     * @param array<int, int> $localDataIds Cacti data_local.id values
     * @return array<int, array{in_bps: ?int, out_bps: ?int}> keyed by
     *         local_data_id; ids with no DSStats rows are absent.
     *
     * @throws CactiUnavailableException
     */
    public function getTrafficRates(array $localDataIds): array;
}
