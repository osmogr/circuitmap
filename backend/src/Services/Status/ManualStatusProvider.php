<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

use CircuitMap\Models\CircuitRepository;

/**
 * Reads the status stored on the circuit row, written by users via the UI
 * (source "manual"). The Cacti poller (bin/poll_cacti.php) polls device
 * status onto locations, not circuits, so this is the only circuit status
 * path.
 */
final class ManualStatusProvider implements StatusProviderInterface
{
    public function __construct(private readonly CircuitRepository $circuits)
    {
    }

    public function getStatus(string $circuitUuid): StatusResult
    {
        $circuit = $this->circuits->findByUuid($circuitUuid);
        if ($circuit === null) {
            return new StatusResult('unknown', 'manual', null);
        }

        return new StatusResult(
            (string) ($circuit['status'] ?? 'unknown'),
            (string) ($circuit['status_source'] ?? 'manual'),
            $circuit['status_updated_at'] ?? null
        );
    }
}
