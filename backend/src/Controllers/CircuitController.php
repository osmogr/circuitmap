<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Models\CircuitRepository;
use CircuitMap\Models\LocationRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Kml\GeoJsonConverter;
use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\CircuitAuthorization;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use CircuitMap\Support\StatusColor;
use CircuitMap\Support\Uuid;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Response as SlimResponse;

final class CircuitController
{
    private const ALLOWED_EXTENSIONS = ['kml', 'kmz'];
    private const ALLOWED_KML_MIME_TYPES = [
        'application/vnd.google-earth.kml+xml',
        'text/xml',
        'application/xml',
        'text/plain',
    ];
    private const ALLOWED_KMZ_MIME_TYPES = [
        'application/vnd.google-earth.kmz',
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly CircuitRepository $circuits,
        private readonly CircuitProviderRepository $providers,
        private readonly LocationRepository $locations,
        private readonly AuditLogRepository $auditLog,
        private readonly FileStorageService $storage,
        private readonly KmlParser $parser,
        private readonly KmlValidator $validator,
        private readonly KmlSanitizer $sanitizer,
        private readonly GeoJsonConverter $geoJsonConverter,
        private readonly KmzExtractor $kmzExtractor
    ) {
    }

    public function showUploadForm(Request $request, Response $response): Response
    {
        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'Upload circuit',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('upload', [
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => $currentUser,
                'providers' => $this->providers->listActive(),
                'locations' => $this->locations->listActive(),
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function showNewForm(Request $request, Response $response): Response
    {
        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'New circuit',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('new', [
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => $currentUser,
                'providers' => $this->providers->listActive(),
                'locations' => $this->locations->listActive(),
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Creates a circuit with no geometry yet, then hands off to the editor
     * to populate it. The stored KML is built directly from an empty
     * GeoJSON FeatureCollection via GeoJsonConverter (the same code path
     * the editor's save uses) rather than through KmlParser/KmlValidator:
     * there is no user-supplied file here to parse or sanitize, and
     * KmlValidator's "at least one Placemark" rule is a save-time gate the
     * editor will enforce once the user actually draws something.
     */
    public function createBlank(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : null;
        $tags = is_string($body['tags'] ?? null) ? trim($body['tags']) : null;
        $providerCircuitId = is_string($body['provider_circuit_id'] ?? null) ? trim($body['provider_circuit_id']) : null;
        $orderNumber = is_string($body['order_number'] ?? null) ? trim($body['order_number']) : null;
        $redundant = ($body['redundant'] ?? '') === '1';

        if ($name === '') {
            return $this->renderNewError($response, 'A circuit name is required.', 422);
        }

        [$providerId, $providerError] = $this->resolveProviderId($body['provider_id'] ?? null);
        if ($providerError !== null) {
            return $this->renderNewError($response, $providerError, 422);
        }

        [$aLocationId, $aLocationError] = $this->resolveLocationId($body['a_location_id'] ?? null);
        if ($aLocationError !== null) {
            return $this->renderNewError($response, $aLocationError, 422);
        }
        [$zLocationId, $zLocationError] = $this->resolveLocationId($body['z_location_id'] ?? null);
        if ($zLocationError !== null) {
            return $this->renderNewError($response, $zLocationError, 422);
        }

        $dom = $this->geoJsonConverter->toKml(['features' => []]);
        $normalizedXml = (string) $dom->saveXML();
        $uuid = Uuid::v4();
        $relativePath = $this->storage->saveNew($uuid, $normalizedXml);

        $circuitId = $this->circuits->insert(
            $uuid,
            $name,
            $description,
            $tags,
            (int) $currentUser['id'],
            $relativePath,
            $providerId,
            $providerCircuitId === '' ? null : $providerCircuitId,
            $orderNumber === '' ? null : $orderNumber,
            $redundant,
            $aLocationId,
            $zLocationId
        );

        $this->auditLog->log(
            (int) $currentUser['id'],
            'create',
            $circuitId,
            "name={$name}",
            ClientIp::from($request)
        );

        return (new SlimResponse())->withHeader('Location', BasePath::url("/circuits/{$uuid}/edit"))->withStatus(302);
    }

    public function upload(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : null;
        $tags = is_string($body['tags'] ?? null) ? trim($body['tags']) : null;
        $providerCircuitId = is_string($body['provider_circuit_id'] ?? null) ? trim($body['provider_circuit_id']) : null;
        $orderNumber = is_string($body['order_number'] ?? null) ? trim($body['order_number']) : null;
        $redundant = ($body['redundant'] ?? '') === '1';

        $files = $request->getUploadedFiles();
        $file = $files['kml_file'] ?? null;

        $error = $this->validateUploadInputs($name, $file);
        if ($error !== null) {
            return $this->renderUploadError($response, $error, 422);
        }

        [$providerId, $providerError] = $this->resolveProviderId($body['provider_id'] ?? null);
        if ($providerError !== null) {
            return $this->renderUploadError($response, $providerError, 422);
        }

        [$aLocationId, $aLocationError] = $this->resolveLocationId($body['a_location_id'] ?? null);
        if ($aLocationError !== null) {
            return $this->renderUploadError($response, $aLocationError, 422);
        }
        [$zLocationId, $zLocationError] = $this->resolveLocationId($body['z_location_id'] ?? null);
        if ($zLocationError !== null) {
            return $this->renderUploadError($response, $zLocationError, 422);
        }

        $extension = strtolower((string) pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $this->renderUploadError($response, 'Only .kml or .kmz files are accepted.', 422);
        }

        try {
            $rawXml = $extension === 'kmz'
                ? $this->extractKmlFromKmz($file)
                : $this->readAndSniffKml($file);

            $dom = $this->parser->parse($rawXml);
            $this->validator->validate($dom);
            $this->sanitizer->sanitize($dom);
        } catch (KmlParseException $e) {
            return $this->renderUploadError($response, $e->getMessage(), 422);
        }

        $normalizedXml = (string) $dom->saveXML();
        $uuid = Uuid::v4();
        $relativePath = $this->storage->saveNew($uuid, $normalizedXml);

        $circuitId = $this->circuits->insert(
            $uuid,
            $name,
            $description,
            $tags,
            (int) $currentUser['id'],
            $relativePath,
            $providerId,
            $providerCircuitId === '' ? null : $providerCircuitId,
            $orderNumber === '' ? null : $orderNumber,
            $redundant,
            $aLocationId,
            $zLocationId
        );

        $this->auditLog->log(
            (int) $currentUser['id'],
            'upload',
            $circuitId,
            "name={$name}",
            ClientIp::from($request)
        );

        return (new SlimResponse())->withHeader('Location', BasePath::url('/'))->withStatus(302);
    }

    public function showReport(Request $request, Response $response): Response
    {
        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'All Circuits',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('report', [
                'currentUser' => $currentUser,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function listJson(Request $request, Response $response): Response
    {
        $circuits = array_map(
            static function (array $circuit): array {
                $circuit['statusColor'] = StatusColor::forStatus($circuit['status'] ?? null);
                return $circuit;
            },
            $this->circuits->listVisible()
        );

        return ResponseHelper::json(['circuits' => $circuits]);
    }

    /**
     * @param array<string, string> $args
     */
    public function geoJson(Request $request, Response $response, array $args): Response
    {
        $uuid = $args['uuid'] ?? '';
        $circuit = $this->circuits->findByUuid($uuid);
        if ($circuit === null) {
            return ResponseHelper::json(['error' => 'Circuit not found'], 404);
        }

        try {
            $xml = $this->storage->read($uuid);
            $dom = $this->parser->parse($xml);
        } catch (\Throwable $e) {
            return ResponseHelper::json(['error' => 'Could not load circuit geometry'], 500);
        }

        return ResponseHelper::json($this->geoJsonConverter->toGeoJson($dom));
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(Request $request, Response $response, array $args): Response
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

        // Soft delete: the row and its files are kept (deleted_at set) so
        // circuit_versions/audit_log foreign keys and history stay intact;
        // listVisible()/findByUuid() already exclude soft-deleted circuits.
        $this->circuits->softDelete((int) $circuit['id']);

        $this->auditLog->log(
            (int) $currentUser['id'],
            'delete',
            (int) $circuit['id'],
            "name={$circuit['name']}",
            ClientIp::from($request)
        );

        return ResponseHelper::json(['status' => 'ok']);
    }

    /**
     * @param mixed $rawProviderId
     * @return array{0: ?int, 1: ?string} [providerId, errorMessage]
     */
    private function resolveProviderId($rawProviderId): array
    {
        if (!is_string($rawProviderId) || trim($rawProviderId) === '') {
            return [null, null];
        }

        $providerId = (int) $rawProviderId;
        $provider = $this->providers->findById($providerId);
        if ($provider === null || (int) $provider['is_active'] !== 1) {
            return [null, 'Selected circuit provider is invalid.'];
        }

        return [$providerId, null];
    }

    /**
     * @param mixed $rawLocationId
     * @return array{0: ?int, 1: ?string} [locationId, errorMessage]
     */
    private function resolveLocationId($rawLocationId): array
    {
        if (!is_string($rawLocationId) || trim($rawLocationId) === '') {
            return [null, null];
        }

        $locationId = (int) $rawLocationId;
        $location = $this->locations->findById($locationId);
        if ($location === null || (int) $location['is_active'] !== 1) {
            return [null, 'Selected location is invalid.'];
        }

        return [$locationId, null];
    }

    private function readAndSniffKml(UploadedFileInterface $file): string
    {
        $rawXml = (string) $file->getStream()->getContents();
        $sniffedType = $this->sniffMimeType($rawXml);

        if ($sniffedType !== '' && !in_array($sniffedType, self::ALLOWED_KML_MIME_TYPES, true)) {
            throw new KmlParseException("File content does not look like KML/XML (detected: {$sniffedType}).");
        }

        return $rawXml;
    }

    private function extractKmlFromKmz(UploadedFileInterface $file): string
    {
        $rawBytes = (string) $file->getStream()->getContents();
        $sniffedType = $this->sniffMimeType($rawBytes);

        if ($sniffedType !== '' && !in_array($sniffedType, self::ALLOWED_KMZ_MIME_TYPES, true)) {
            throw new KmlParseException("File content does not look like a KMZ archive (detected: {$sniffedType}).");
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'circuitmap_kmz_');
        if ($tempPath === false) {
            throw new KmlParseException('Could not create a temporary file to process the KMZ upload.');
        }

        try {
            file_put_contents($tempPath, $rawBytes, LOCK_EX);
            return $this->kmzExtractor->extractKml($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    private function sniffMimeType(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $type = (string) finfo_buffer($finfo, $content);
        finfo_close($finfo);
        return $type;
    }

    /**
     * @param mixed $file
     */
    private function validateUploadInputs(string $name, $file): ?string
    {
        if ($name === '') {
            return 'A circuit name is required.';
        }

        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return 'A valid KML file is required.';
        }

        return null;
    }

    private function renderUploadError(Response $response, string $message, int $status): Response
    {
        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'Upload circuit',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('upload', [
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => $currentUser,
                'providers' => $this->providers->listActive(),
                'locations' => $this->locations->listActive(),
                'error' => $message,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    private function renderNewError(Response $response, string $message, int $status): Response
    {
        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'New circuit',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('new', [
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => $currentUser,
                'providers' => $this->providers->listActive(),
                'locations' => $this->locations->listActive(),
                'error' => $message,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }
}
