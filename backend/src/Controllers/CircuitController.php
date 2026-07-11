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
use CircuitMap\Services\Kml\KmlFolderSplitter;
use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlSanitizer;
use CircuitMap\Services\Kml\KmlValidator;
use CircuitMap\Services\Kml\KmzExtractor;
use CircuitMap\Services\Storage\FileStorageService;
use CircuitMap\Services\Storage\PendingImportStorage;
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
        private readonly \PDO $pdo,
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
        private readonly KmzExtractor $kmzExtractor,
        private readonly KmlFolderSplitter $folderSplitter,
        private readonly PendingImportStorage $pendingImports
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

        if ($this->folderSplitter->isSplittable($dom)) {
            return $this->renderSplitPreview($response, $dom, [
                'user_id' => (int) $currentUser['id'],
                'name' => $name,
                'description' => $description,
                'tags' => $tags,
                'provider_id' => $providerId,
                'provider_circuit_id' => $providerCircuitId === '' ? null : $providerCircuitId,
                'order_number' => $orderNumber === '' ? null : $orderNumber,
                'redundant' => $redundant,
                'a_location_id' => $aLocationId,
                'z_location_id' => $zLocationId,
                'original_filename' => (string) $file->getClientFilename(),
            ]);
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

    public function exportCsv(Request $request, Response $response): Response
    {
        $rows = $this->circuits->listVisibleAllColumns();

        // With zero circuits the header still has to exist, so derive it
        // from the live schema the same way the query builds its columns.
        if ($rows !== []) {
            $header = array_keys($rows[0]);
        } else {
            $header = [];
            foreach ($this->pdo->query('PRAGMA table_info(circuits)')->fetchAll() as $column) {
                $header[] = (string) $column['name'];
            }
            $header = array_merge($header, ['provider_name', 'a_location_name', 'z_location_name']);
        }

        $buffer = fopen('php://temp', 'r+');
        if ($buffer === false) {
            throw new \RuntimeException('Could not allocate a buffer for the CSV export.');
        }
        fputcsv($buffer, $header);
        foreach ($rows as $row) {
            fputcsv($buffer, $row);
        }
        rewind($buffer);
        $csv = (string) stream_get_contents($buffer);
        fclose($buffer);

        // The report is public unless REQUIRE_AUTH_FOR_VIEW, so the export
        // may be anonymous; audit_log.user_id is nullable for that case.
        $currentUser = $this->auth->currentUser();
        $this->auditLog->log(
            $currentUser !== null ? (int) $currentUser['id'] : null,
            'export',
            null,
            sprintf('format=csv circuits=%d', count($rows)),
            ClientIp::from($request)
        );

        $filename = 'circuitmap-circuits-' . gmdate('Ymd') . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($csv));
    }

    public function listJson(Request $request, Response $response): Response
    {
        $circuits = array_map(
            static function (array $circuit): array {
                $circuit['statusColor'] = StatusColor::forStatus($circuit['status'] ?? null);
                // Derived server-side (like statusColor) so every consumer
                // agrees on the definition: the busier of the two directions
                // against the provisioned capacity.
                $capacity = (int) ($circuit['capacity_bps'] ?? 0);
                $hasUsage = ($circuit['usage_in_bps'] ?? null) !== null
                    || ($circuit['usage_out_bps'] ?? null) !== null;
                $peak = max((int) ($circuit['usage_in_bps'] ?? 0), (int) ($circuit['usage_out_bps'] ?? 0));
                $circuit['utilizationPct'] = ($capacity > 0 && $hasUsage)
                    ? round($peak / $capacity * 100, 1)
                    : null;
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
     * Second step of a multi-folder import: the sanitized KML sits in
     * pending storage under a server-generated token; this either creates
     * one circuit per selected folder (all-or-nothing) or falls back to a
     * plain single-circuit import of the whole file.
     */
    public function confirmSplit(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $token = is_string($body['pending_token'] ?? null) ? $body['pending_token'] : '';
        $include = is_array($body['include'] ?? null) ? array_values(array_filter($body['include'], 'is_string')) : [];
        $names = is_array($body['names'] ?? null) ? $body['names'] : [];

        $pending = $this->pendingImports->read($token);
        if ($pending === null || (int) ($pending['meta']['user_id'] ?? 0) !== (int) $currentUser['id']) {
            return $this->renderUploadError(
                $response,
                'This import session has expired. Please upload the file again.',
                410
            );
        }
        $meta = $pending['meta'];

        try {
            $dom = $this->parser->parse($pending['kml']);
        } catch (KmlParseException $e) {
            return $this->renderUploadError($response, $e->getMessage(), 422);
        }

        // Providers/locations may have been deactivated since the preview.
        [$providerId, $providerError] = $this->resolveProviderId((string) ($meta['provider_id'] ?? ''));
        [$aLocationId, $aLocationError] = $this->resolveLocationId((string) ($meta['a_location_id'] ?? ''));
        [$zLocationId, $zLocationError] = $this->resolveLocationId((string) ($meta['z_location_id'] ?? ''));
        $resolveError = $providerError ?? $aLocationError ?? $zLocationError;
        if ($resolveError !== null) {
            return $this->renderSplitPage($response, $token, $meta, $resolveError, 422, $names, $include);
        }

        if (($body['mode'] ?? '') === 'single') {
            $uuid = Uuid::v4();
            $relativePath = $this->storage->saveNew($uuid, $pending['kml']);
            $circuitId = $this->insertCircuitFromMeta($uuid, (string) $meta['name'], $relativePath, $meta, $providerId, $aLocationId, $zLocationId, (int) $currentUser['id']);
            $this->auditLog->log((int) $currentUser['id'], 'upload', $circuitId, 'name=' . $meta['name'], ClientIp::from($request));
            $this->pendingImports->delete($token);
            return (new SlimResponse())->withHeader('Location', BasePath::url('/'))->withStatus(302);
        }

        $inventory = [];
        foreach (($meta['folders'] ?? []) as $candidate) {
            $inventory[(string) $candidate['key']] = $candidate;
        }

        // Phase 1: validate every selected folder before writing anything,
        // so a bad folder cannot leave a partial import behind.
        $toCreate = [];
        foreach (array_unique($include) as $key) {
            $candidate = $inventory[$key] ?? null;
            if ($candidate === null) {
                continue; // forged or stale key
            }
            $label = $this->candidateLabel($candidate);
            $circuitName = trim((string) ($names[$key] ?? ''));
            if ($circuitName === '' || mb_strlen($circuitName) > 200) {
                return $this->renderSplitPage($response, $token, $meta, "Provide a circuit name (up to 200 characters) for \"{$label}\".", 422, $names, $include);
            }
            try {
                $splitDom = $this->folderSplitter->extract($dom, $key);
                $this->validator->validate($splitDom);
            } catch (KmlParseException $e) {
                return $this->renderSplitPage($response, $token, $meta, "\"{$label}\": {$e->getMessage()}", 422, $names, $include);
            }
            $toCreate[] = ['label' => $label, 'name' => $circuitName, 'xml' => (string) $splitDom->saveXML()];
        }

        if ($toCreate === []) {
            return $this->renderSplitPage($response, $token, $meta, 'Select at least one folder to import.', 422, $names, $include);
        }

        // Phase 2: all rows in one transaction. A rollback leaves only
        // orphaned circuits/{uuid}/ dirs, which nothing ever reads.
        $created = [];
        try {
            $this->inTransaction(function () use ($toCreate, $meta, $providerId, $aLocationId, $zLocationId, $currentUser, &$created): void {
                foreach ($toCreate as $item) {
                    $uuid = Uuid::v4();
                    $relativePath = $this->storage->saveNew($uuid, $item['xml']);
                    $circuitId = $this->insertCircuitFromMeta($uuid, $item['name'], $relativePath, $meta, $providerId, $aLocationId, $zLocationId, (int) $currentUser['id']);
                    $created[] = ['id' => $circuitId, 'name' => $item['name'], 'label' => $item['label']];
                }
            });
        } catch (\Throwable) {
            return $this->renderSplitPage($response, $token, $meta, 'Import failed; no circuits were created.', 500, $names, $include);
        }

        foreach ($created as $circuit) {
            $this->auditLog->log(
                (int) $currentUser['id'],
                'upload',
                (int) $circuit['id'],
                "name={$circuit['name']} split_folder={$circuit['label']}",
                ClientIp::from($request)
            );
        }
        $this->pendingImports->delete($token);

        return (new SlimResponse())->withHeader('Location', BasePath::url('/'))->withStatus(302);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function insertCircuitFromMeta(
        string $uuid,
        string $name,
        string $relativePath,
        array $meta,
        ?int $providerId,
        ?int $aLocationId,
        ?int $zLocationId,
        int $ownerId
    ): int {
        return $this->circuits->insert(
            $uuid,
            $name,
            isset($meta['description']) ? (string) $meta['description'] : null,
            isset($meta['tags']) ? (string) $meta['tags'] : null,
            $ownerId,
            $relativePath,
            $providerId,
            isset($meta['provider_circuit_id']) ? (string) $meta['provider_circuit_id'] : null,
            isset($meta['order_number']) ? (string) $meta['order_number'] : null,
            (bool) ($meta['redundant'] ?? false),
            $aLocationId,
            $zLocationId
        );
    }

    /**
     * @param array<string, mixed> $meta shared form fields; folder inventory is added here
     */
    private function renderSplitPreview(Response $response, \DOMDocument $dom, array $meta): Response
    {
        $meta['folders'] = $this->folderSplitter->enumerate($dom);
        $token = $this->pendingImports->save((string) $dom->saveXML(), $meta);
        return $this->renderSplitPage($response, $token, $meta, null, 200);
    }

    /**
     * Renders the folder-selection page, preserving the user's edits when
     * re-rendering after a confirm-time validation error.
     *
     * @param array<string, mixed> $meta
     * @param array<mixed> $submittedNames names[<key>] from the confirm request
     * @param array<int, string>|null $submittedIncludes include[] from the confirm request; null on first render
     */
    private function renderSplitPage(
        Response $response,
        string $token,
        array $meta,
        ?string $error,
        int $status,
        array $submittedNames = [],
        ?array $submittedIncludes = null
    ): Response {
        $originalName = (string) ($meta['name'] ?? '');

        $rows = [];
        foreach (($meta['folders'] ?? []) as $index => $candidate) {
            $key = (string) $candidate['key'];
            $empty = (int) $candidate['placemarkCount'] === 0;
            $default = $key === KmlFolderSplitter::UNGROUPED_KEY
                ? "{$originalName} (ungrouped)"
                : ((string) $candidate['name'] !== '' ? (string) $candidate['name'] : sprintf('%s - Folder %d', $originalName, $index + 1));
            $rows[] = [
                'key' => $key,
                'label' => $this->candidateLabel($candidate),
                'placemarkCount' => (int) $candidate['placemarkCount'],
                'value' => isset($submittedNames[$key]) ? (string) $submittedNames[$key] : $default,
                'included' => !$empty && ($submittedIncludes === null || in_array($key, $submittedIncludes, true)),
                'disabled' => $empty,
            ];
        }

        $currentUser = $this->auth->currentUser();
        $html = View::render('layout', [
            'title' => 'Split circuits',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('split', [
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => $currentUser,
                'token' => $token,
                'rows' => $rows,
                'shared' => $this->sharedMetaSummary($meta),
                'originalName' => $originalName,
                'originalFilename' => (string) ($meta['original_filename'] ?? ''),
                'error' => $error,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function candidateLabel(array $candidate): string
    {
        if ((string) $candidate['key'] === KmlFolderSplitter::UNGROUPED_KEY) {
            return 'Ungrouped placemarks';
        }
        return (string) $candidate['name'] !== '' ? (string) $candidate['name'] : '(unnamed folder)';
    }

    /**
     * Display strings for the "applied to every circuit" summary box,
     * empty fields omitted.
     *
     * @param array<string, mixed> $meta
     * @return array<string, string>
     */
    private function sharedMetaSummary(array $meta): array
    {
        $providerName = '';
        if (isset($meta['provider_id']) && $meta['provider_id'] !== null) {
            $provider = $this->providers->findById((int) $meta['provider_id']);
            $providerName = $provider !== null ? (string) $provider['name'] : '';
        }
        $locationName = function ($id): string {
            if ($id === null) {
                return '';
            }
            $location = $this->locations->findById((int) $id);
            return $location !== null ? (string) $location['name'] : '';
        };

        $fields = [
            'Description' => (string) ($meta['description'] ?? ''),
            'Tags' => (string) ($meta['tags'] ?? ''),
            'Circuit Provider' => $providerName,
            'A-Location' => $locationName($meta['a_location_id'] ?? null),
            'Z-Location' => $locationName($meta['z_location_id'] ?? null),
            'Circuit ID' => (string) ($meta['provider_circuit_id'] ?? ''),
            'Order Number' => (string) ($meta['order_number'] ?? ''),
            'Redundant' => ($meta['redundant'] ?? false) ? 'Yes' : '',
        ];
        return array_filter($fields, static fn (string $value): bool => $value !== '');
    }

    /**
     * Runs $work inside a single database transaction, rolling back and
     * re-throwing if it raises. Filesystem writes inside $work are not
     * transactional; callers order them so a rollback leaves only benign
     * artifacts (see EditController::update()).
     */
    private function inTransaction(callable $work): void
    {
        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
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
