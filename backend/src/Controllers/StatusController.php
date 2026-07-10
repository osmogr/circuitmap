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
 * Manual status setting: a user picks a value from a dropdown and it is
 * stored on the circuit row via CircuitRepository::updateStatus with
 * status_source "manual". Circuit status is manual-only — the Cacti poller
 * (bin/poll_cacti.php) writes device status onto locations, never onto
 * circuits. getStatus() reads the stored value via the
 * StatusProviderInterface binding in App.php.
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
