<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

use CircuitMap\Models\CircuitRepository;

/**
 * Reads the status stored on the circuit row. Despite the name this now
 * serves two write paths: users setting status via the UI (source
 * "manual") and the supervisord-run Cacti poller (bin/poll_cacti.php,
 * source "cacti"), which overwrites status on every poll for circuits
 * that have a cacti_host_id mapping.
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
