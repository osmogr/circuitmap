<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LocationController
{
    public function __construct(
        private readonly LocationRepository $locations,
        private readonly AuditLogRepository $auditLog,
        private readonly CsrfService $csrf
    ) {
    }

    public function showLocations(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $html = View::render('layout', [
            'title' => 'Manage locations',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/locations', [
                'csrfToken' => $this->csrf->getToken(),
                'locations' => $this->locations->listAll(),
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createLocation(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $address = $this->nullableTrim($body['address'] ?? null);
        $notes = $this->nullableTrim($body['notes'] ?? null);

        if ($name === '') {
            return $this->renderLocationsPage($response, $currentUser, 'A location name is required.', 422);
        }
        if ($this->locations->findByName($name) !== null) {
            return $this->renderLocationsPage($response, $currentUser, 'That location name already exists.', 422);
        }

        $newLocationId = $this->locations->insert($name, $address, $notes);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'location_create',
            null,
            "created_location_id={$newLocationId} name={$name}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/locations'))->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateLocation(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $targetId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $address = $this->nullableTrim($body['address'] ?? null);
        $notes = $this->nullableTrim($body['notes'] ?? null);

        if ($this->locations->findById($targetId) === null) {
            return $this->renderLocationsPage($response, $currentUser, 'Location not found.', 404);
        }
        if ($name === '') {
            return $this->renderLocationsPage($response, $currentUser, 'A location name is required.', 422);
        }
        $existing = $this->locations->findByName($name);
        if ($existing !== null && (int) $existing['id'] !== $targetId) {
            return $this->renderLocationsPage($response, $currentUser, 'That location name already exists.', 422);
        }

        $this->locations->update($targetId, $name, $address, $notes);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'location_update',
            null,
            "target_location_id={$targetId}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/locations'))->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     */
    public function setActive(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $targetId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $active = ($body['active'] ?? '') === '1';

        if ($this->locations->findById($targetId) === null) {
            return $this->renderLocationsPage($response, $currentUser, 'Location not found.', 404);
        }

        $this->locations->setActive($targetId, $active);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'location_update',
            null,
            'target_location_id=' . $targetId . ' active=' . ($active ? '1' : '0'),
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/locations'))->withStatus(302);
    }

    /**
     * @param mixed $value
     */
    private function nullableTrim($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param mixed $currentUser
     */
    private function renderLocationsPage(Response $response, $currentUser, string $error, int $status): Response
    {
        $html = View::render('layout', [
            'title' => 'Manage locations',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/locations', [
                'csrfToken' => $this->csrf->getToken(),
                'locations' => $this->locations->listAll(),
                'error' => $error,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }
}
