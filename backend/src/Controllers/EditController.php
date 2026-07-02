<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\CircuitVersionRepository;
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
        $geojson = is_array($body['geojson'] ?? null) ? $body['geojson'] : null;

        if ($name === '') {
            return ResponseHelper::json(['error' => 'A circuit name is required.'], 422);
        }
        if ($geojson === null) {
            return ResponseHelper::json(['error' => 'Missing geometry payload.'], 422);
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
        $this->circuits->updateAfterEdit((int) $circuit['id'], $name, $description, $tags, $newVersionNumber);

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
}
