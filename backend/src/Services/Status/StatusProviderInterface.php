<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

/**
 * Contract for reading a circuit's current status. ManualStatusProvider
 * (the bound implementation) reads the status stored on the circuit row,
 * which is written either by a user via the edit UI (source "manual") or
 * by the Cacti poller, bin/poll_cacti.php (source "cacti"). An adapter
 * that queries an external system live at request time would implement
 * this interface and be wired in wherever ManualStatusProvider is
 * constructed today, without any change to the consumers of StatusResult.
 */
interface StatusProviderInterface
{
    public function getStatus(string $circuitUuid): StatusResult;
}
