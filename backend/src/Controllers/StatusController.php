<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Services\Status\StatusProviderInterface;
use CircuitMap\Support\CircuitAuthorization;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Manual status setting is the one working example of the deferred status
 * integration point: a user picks a value from a dropdown, it is stored
 * directly on the circuit row via CircuitRepository::updateStatus, and
 * status_source is recorded as "manual". A future polling/webhook adapter
 * would call the same repository method with a different source label,
 * and would be wired in by swapping the StatusProviderInterface binding
 * constructed in App.php (currently ManualStatusProvider) for the new
 * adapter; getStatus() below does not otherwise change.
 */
final class StatusController
{
    private const VALID_STATUSES = ['unknown', 'up', 'degraded', 'down'];

    public function __construct(
        private readonly CircuitRepository $circuits,
        private readonly AuditLogRepository $auditLog,
        private readonly StatusProviderInterface $statusProvider
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function getStatus(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? '';
        if ($this->circuits->findByUuid($uuid) === null) {
            return ResponseHelper::json(['error' => 'Circuit not found'], 404);
        }

        $result = $this->statusProvider->getStatus($uuid);

        return ResponseHelper::json([
            'status' => $result->status,
            'source' => $result->source,
            'updatedAt' => $result->updatedAt,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function setStatus(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? '';
        $circuit = $this->circuits->findByUuid($uuid);
        $currentUser = $request->getAttribute('currentUser');

        if ($circuit === null) {
            return ResponseHelper::json(['error' => 'Circuit not found'], 404);
        }
        if (!CircuitAuthorization::canEdit($circuit, $currentUser)) {
            return ResponseHelper::json(['error' => 'Forbidden'], 403);
        }

        $body = (array) $request->getParsedBody();
        $status = is_string($body['status'] ?? null) ? $body['status'] : '';
        if (!in_array($status, self::VALID_STATUSES, true)) {
            return ResponseHelper::json(['error' => 'Invalid status value.'], 422);
        }

        $this->circuits->updateStatus((int) $circuit['id'], $status, 'manual');

        $this->auditLog->log(
            (int) $currentUser['id'],
            'status_change',
            (int) $circuit['id'],
            "status={$status}",
            ClientIp::from($request)
        );

        return ResponseHelper::json(['status' => 'ok', 'newStatus' => $status]);
    }
}
