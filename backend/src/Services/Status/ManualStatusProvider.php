<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

use CircuitMap\Models\CircuitRepository;

/**
 * The working example adapter: status is whatever a user last set via the
 * UI (CircuitRepository::updateStatus), stored directly on the circuit
 * row. A future polling or webhook-based adapter would instead query an
 * external system here and would typically also need a scheduler or
 * webhook route to call CircuitRepository::updateStatus on a timer/event,
 * neither of which exists yet.
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
