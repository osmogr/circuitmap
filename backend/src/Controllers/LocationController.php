<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\LocationIcon;
use CircuitMap\Support\Response as ResponseHelper;
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
                'iconOptions' => LocationIcon::options(),
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Locations with a valid address (geolocated + active), for the map to
     * plot a marker per site.
     */
    public function listJson(Request $request, Response $response): Response
    {
        $locations = array_map(
            static function (array $location): array {
                $location['latitude'] = (float) $location['latitude'];
                $location['longitude'] = (float) $location['longitude'];
                $location['iconSymbol'] = LocationIcon::symbolFor($location['icon'] ?? null);
                return $location;
            },
            $this->locations->listMappable()
        );

        return ResponseHelper::json(['locations' => $locations]);
    }

    public function createLocation(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $address = $this->nullableTrim($body['address'] ?? null);
        $notes = $this->nullableTrim($body['notes'] ?? null);
        [$latitude, $longitude, $icon, $coordError] = $this->parseMapFields($body);

        if ($name === '') {
            return $this->renderLocationsPage($response, $currentUser, 'A location name is required.', 422);
        }
        if ($this->locations->findByName($name) !== null) {
            return $this->renderLocationsPage($response, $currentUser, 'That location name already exists.', 422);
        }
        if ($coordError !== null) {
            return $this->renderLocationsPage($response, $currentUser, $coordError, 422);
        }

        $newLocationId = $this->locations->insert($name, $address, $notes, $latitude, $longitude, $icon);
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
        [$latitude, $longitude, $icon, $coordError] = $this->parseMapFields($body);

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
        if ($coordError !== null) {
            return $this->renderLocationsPage($response, $currentUser, $coordError, 422);
        }

        $this->locations->update($targetId, $name, $address, $notes, $latitude, $longitude, $icon);
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
     * Parses and validates the latitude/longitude/icon fields shared by
     * create and update. Both coordinates must be present together (or
     * both absent) for a location to be "map-ready".
     *
     * @param array<string, mixed> $body
     * @return array{0: ?float, 1: ?float, 2: ?string, 3: ?string}
     */
    private function parseMapFields(array $body): array
    {
        $latitudeRaw = $this->nullableTrim($body['latitude'] ?? null);
        $longitudeRaw = $this->nullableTrim($body['longitude'] ?? null);
        $icon = $this->nullableTrim($body['icon'] ?? null);

        if ($latitudeRaw === null && $longitudeRaw === null) {
            return [null, null, null, null];
        }
        if ($latitudeRaw === null || $longitudeRaw === null) {
            return [null, null, null, 'Latitude and longitude must be set together.'];
        }
        if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
            return [null, null, null, 'Latitude and longitude must be numbers.'];
        }

        $latitude = (float) $latitudeRaw;
        $longitude = (float) $longitudeRaw;
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return [null, null, null, 'Latitude must be between -90 and 90, longitude between -180 and 180.'];
        }
        if ($icon !== null && !LocationIcon::isValid($icon)) {
            return [null, null, null, 'Unknown icon selection.'];
        }

        return [$latitude, $longitude, $icon ?? 'generic', null];
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
                'iconOptions' => LocationIcon::options(),
                'error' => $error,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }
}
