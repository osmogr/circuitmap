<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

/**
 * Contract for a real-time status data source. This pass only ships
 * ManualStatusProvider (reads/writes the manually-set status stored on the
 * circuit row); no polling loop, webhook receiver, or vendor API client is
 * implemented yet. A future adapter (see README) would implement this
 * interface and be wired in wherever ManualStatusProvider is constructed
 * today, without any change to the map/API code that consumes StatusResult.
 */
interface StatusProviderInterface
{
    public function getStatus(string $circuitUuid): StatusResult;
}
