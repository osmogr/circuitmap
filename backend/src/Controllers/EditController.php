<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\CircuitVersionRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\CircuitAuthorization;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class EditController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly CircuitRepository $circuits,
        private readonly CircuitProviderRepository $providers,
        private readonly LocationRepository $locations,
        private readonly CircuitVersionRepository $versions,
        private readonly AuditLogRepository $auditLog,
        private readonly FileStorageService $storage,
        private readonly KmlParser $parser,
        private readonly KmlValidator $validator,
        private readonly KmlSanitizer $sanitizer,
        private readonly GeoJsonConverter $geoJsonConverter
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function showEditForm(Request $request, Response $response, array $args): Response
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

        $html = View::render('layout', [
            'title' => 'Edit ' . $circuit['name'],
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('edit', [
                'csrfToken' => $this->csrf->getToken(),
                'circuit' => $circuit,
                'currentUser' => $currentUser,
                'providers' => $this->providersForEditForm($circuit),
                'locations' => $this->locationsForEditForm($circuit),
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, string> $args
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? '';
        $circuit = $this->circuits->findByUuid($uuid);
        $currentUser = $request->getAttribute('currentUser');

        if ($circuit === null) {
            return ResponseHelper::json(['error' => 'Circuit not found'], 404);
        }

        // Ownership/role check is repeated here even though the edit page
        // already checked it; the page having loaded is not proof of
        // authorization for this specific write.
        if (!CircuitAuthorization::canEdit($circuit, $currentUser)) {
            return ResponseHelper::json(['error' => 'Forbidden'], 403);
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return ResponseHelper::json(['error' => 'Invalid request body'], 422);
        }

        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : null;
        $tags = is_string($body['tags'] ?? null) ? trim($body['tags']) : null;
        $providerCircuitId = is_string($body['provider_circuit_id'] ?? null) ? trim($body['provider_circuit_id']) : null;
        $orderNumber = is_string($body['order_number'] ?? null) ? trim($body['order_number']) : null;
        $redundant = ($body['redundant'] ?? '') === '1';
        $geojson = is_array($body['geojson'] ?? null) ? $body['geojson'] : null;

        if ($name === '') {
            return ResponseHelper::json(['error' => 'A circuit name is required.'], 422);
        }
        if ($geojson === null) {
            return ResponseHelper::json(['error' => 'Missing geometry payload.'], 422);
        }

        $providerId = null;
        $rawProviderId = $body['provider_id'] ?? null;
        if ($rawProviderId !== null && $rawProviderId !== '') {
            $providerId = (int) $rawProviderId;
            $provider = $this->providers->findById($providerId);
            $keepingExistingProvider = $providerId === (int) ($circuit['provider_id'] ?? 0);
            if ($provider === null || (!$keepingExistingProvider && (int) $provider['is_active'] !== 1)) {
                return ResponseHelper::json(['error' => 'Selected circuit provider is invalid.'], 422);
            }
        }

        [$aLocationId, $aLocationError] = $this->resolveLocationId(
            $body['a_location_id'] ?? null,
            $circuit['a_location_id'] ?? null,
            'A-Location'
        );
        if ($aLocationError !== null) {
            return ResponseHelper::json(['error' => $aLocationError], 422);
        }
        [$zLocationId, $zLocationError] = $this->resolveLocationId(
            $body['z_location_id'] ?? null,
            $circuit['z_location_id'] ?? null,
            'Z-Location'
        );
        if ($zLocationError !== null) {
            return ResponseHelper::json(['error' => $zLocationError], 422);
        }

        try {
            // The submitted GeoJSON is converted back to KML server-side and
            // re-validated/re-sanitized through the exact same pipeline as a
            // fresh upload; edits are never exempt from these checks just
            // because they came from the trusted editor UI.
            $dom = $this->geoJsonConverter->toKml($geojson);
            $this->validator->validate($dom);
            $this->sanitizer->sanitize($dom);
        } catch (KmlParseException $e) {
            return ResponseHelper::json(['error' => $e->getMessage()], 422);
        }

        $normalizedXml = (string) $dom->saveXML();
        $oldVersionNumber = (int) $circuit['current_version'];
        $newVersionNumber = $oldVersionNumber + 1;

        $this->storage->archiveCurrent($uuid, $oldVersionNumber);
        $this->versions->insert(
            (int) $circuit['id'],
            $oldVersionNumber,
            "circuits/{$uuid}/versions/v{$oldVersionNumber}.kml",
            $circuit['name'],
            $circuit['description'],
            (int) $currentUser['id']
        );

        $this->storage->overwriteCurrent($uuid, $normalizedXml);
        $this->circuits->updateAfterEdit(
            (int) $circuit['id'],
            $name,
            $description,
            $tags,
            $newVersionNumber,
            $providerId,
            $providerCircuitId === '' ? null : $providerCircuitId,
            $orderNumber === '' ? null : $orderNumber,
            $redundant,
            $aLocationId,
            $zLocationId
        );

        $this->auditLog->log(
            (int) $currentUser['id'],
            'edit',
            (int) $circuit['id'],
            "version={$newVersionNumber}",
            ClientIp::from($request)
        );

        return ResponseHelper::json(['status' => 'ok', 'version' => $newVersionNumber]);
    }

    /**
     * @param array<string, string> $args
     */
    public function listVersions(Request $request, Response $response, array $args): Response
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

        return ResponseHelper::json(['versions' => $this->versions->listForCircuit((int) $circuit['id'])]);
    }

    /**
     * @param array<string, string> $args
     */
    public function revert(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? '';
        $versionNumber = (int) ($args['version'] ?? 0);
        $circuit = $this->circuits->findByUuid($uuid);
        $currentUser = $request->getAttribute('currentUser');

        if ($circuit === null) {
            return ResponseHelper::json(['error' => 'Circuit not found'], 404);
        }
        if (!CircuitAuthorization::canEdit($circuit, $currentUser)) {
            return ResponseHelper::json(['error' => 'Forbidden'], 403);
        }
        if ($versionNumber < 1) {
            return ResponseHelper::json(['error' => 'Invalid version number'], 422);
        }

        try {
            $targetContent = $this->storage->readVersion($uuid, $versionNumber);
        } catch (\Throwable $e) {
            return ResponseHelper::json(['error' => 'Version not found'], 404);
        }

        $oldVersionNumber = (int) $circuit['current_version'];
        $newVersionNumber = $oldVersionNumber + 1;

        // Reverting also archives what it replaces, so a revert is itself
        // non-destructive and can be undone.
        $this->storage->archiveCurrent($uuid, $oldVersionNumber);
        $this->versions->insert(
            (int) $circuit['id'],
            $oldVersionNumber,
            "circuits/{$uuid}/versions/v{$oldVersionNumber}.kml",
            $circuit['name'],
            $circuit['description'],
            (int) $currentUser['id']
        );

        $this->storage->overwriteCurrent($uuid, $targetContent);
        $this->circuits->updateAfterRevert((int) $circuit['id'], $newVersionNumber);

        $this->auditLog->log(
            (int) $currentUser['id'],
            'edit',
            (int) $circuit['id'],
            "reverted_to=v{$versionNumber} new_version={$newVersionNumber}",
            ClientIp::from($request)
        );

        return ResponseHelper::json(['status' => 'ok', 'version' => $newVersionNumber]);
    }

    /**
     * @param array<string, mixed> $circuit
     * @return array<int, array<string, mixed>>
     */
    private function providersForEditForm(array $circuit): array
    {
        $providers = $this->providers->listActive();

        // A circuit can be pointing at a provider that's since been
        // deactivated; it must still appear (and stay selected) in the
        // dropdown, otherwise saving the form again would silently clear
        // the circuit's provider_id.
        $currentProviderId = $circuit['provider_id'] ?? null;
        if ($currentProviderId !== null) {
            $alreadyListed = array_filter($providers, static fn (array $p) => (int) $p['id'] === (int) $currentProviderId);
            if ($alreadyListed === []) {
                $current = $this->providers->findById((int) $currentProviderId);
                if ($current !== null) {
                    $current['name'] .= ' (inactive)';
                    $providers[] = $current;
                }
            }
        }

        return $providers;
    }

    /**
     * @param mixed $rawLocationId
     * @param mixed $currentLocationId
     * @return array{0: ?int, 1: ?string} [locationId, errorMessage]
     */
    private function resolveLocationId($rawLocationId, $currentLocationId, string $label): array
    {
        if ($rawLocationId === null || $rawLocationId === '') {
            return [null, null];
        }

        $locationId = (int) $rawLocationId;
        $location = $this->locations->findById($locationId);
        $keepingExistingLocation = $locationId === (int) ($currentLocationId ?? 0);
        if ($location === null || (!$keepingExistingLocation && (int) $location['is_active'] !== 1)) {
            return [null, "Selected {$label} is invalid."];
        }

        return [$locationId, null];
    }

    /**
     * @param array<string, mixed> $circuit
     * @return array<int, array<string, mixed>>
     */
    private function locationsForEditForm(array $circuit): array
    {
        $locations = $this->locations->listActive();
        $listedIds = array_map(static fn (array $l) => (int) $l['id'], $locations);

        // A circuit's A/Z endpoints can point at a location that's since
        // been deactivated; they must still appear (and stay selected) in
        // the dropdowns, otherwise saving the form again would silently
        // clear the circuit's location.
        foreach ([$circuit['a_location_id'] ?? null, $circuit['z_location_id'] ?? null] as $currentLocationId) {
            if ($currentLocationId === null || in_array((int) $currentLocationId, $listedIds, true)) {
                continue;
            }
            $current = $this->locations->findById((int) $currentLocationId);
            if ($current !== null) {
                $current['name'] .= ' (inactive)';
                $locations[] = $current;
                $listedIds[] = (int) $current['id'];
            }
        }

        return $locations;
    }
}
