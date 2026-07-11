<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Services\Instance\InstanceExportService;
use CircuitMap\Services\Instance\InstanceImportException;
use CircuitMap\Services\Instance\InstanceImportService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class InstanceTransferController
{
    private const ALLOWED_ZIP_MIME_TYPES = [
        'application/zip',
        'application/x-zip-compressed',
        'application/octet-stream',
    ];

    public function __construct(
        private readonly InstanceExportService $exporter,
        private readonly InstanceImportService $importer,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly AuditLogRepository $auditLog
    ) {
    }

    public function showForm(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        return $this->renderForm(
            $response,
            $request->getAttribute('currentUser'),
            null,
            ($query['imported'] ?? '') === '1' ? 'Import complete. All data from the archive has been restored.' : null
        );
    }

    public function export(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        set_time_limit(300);

        $result = $this->exporter->buildArchive();

        $this->auditLog->log(
            (int) $currentUser['id'],
            'instance_export',
            null,
            sprintf(
                'users=%d circuits=%d versions=%d providers=%d locations=%d audit=%d',
                $result['counts']['users'],
                $result['counts']['circuits'],
                $result['counts']['circuit_versions'],
                $result['counts']['circuit_providers'],
                $result['counts']['locations'],
                $result['counts']['audit_log']
            ),
            ClientIp::from($request)
        );

        $size = filesize($result['path']);
        $handle = fopen($result['path'], 'rb');
        if ($size === false || $handle === false) {
            @unlink($result['path']);
            throw new \RuntimeException('Could not read the generated instance export.');
        }
        // The open handle keeps the bytes readable after unlink, so no temp
        // file is left behind and the archive is never loaded into memory.
        @unlink($result['path']);

        $filename = 'circuitmap-instance-' . gmdate('Ymd-His') . '.zip';
        return $response
            ->withBody(new \Slim\Psr7\Stream($handle))
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) $size);
    }

    public function import(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        set_time_limit(300);

        $body = (array) $request->getParsedBody();
        if (($body['confirm'] ?? '') !== 'REPLACE') {
            return $this->renderForm($response, $currentUser, 'Type REPLACE in the confirmation box to run the import.', null, 422);
        }

        $files = $request->getUploadedFiles();
        $file = $files['archive'] ?? null;
        if (!$file instanceof \Psr\Http\Message\UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->renderForm($response, $currentUser, 'A valid export archive (.zip) is required.', null, 422);
        }
        $extension = strtolower((string) pathinfo((string) $file->getClientFilename(), PATHINFO_EXTENSION));
        if ($extension !== 'zip') {
            return $this->renderForm($response, $currentUser, 'Only .zip instance export archives are accepted.', null, 422);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'circuitmap_import_');
        if ($tempPath === false) {
            throw new \RuntimeException('Could not create a temporary file for the uploaded archive.');
        }

        try {
            $file->moveTo($tempPath);

            $sniffedType = $this->sniffMimeType($tempPath);
            if ($sniffedType !== '' && !in_array($sniffedType, self::ALLOWED_ZIP_MIME_TYPES, true)) {
                return $this->renderForm($response, $currentUser, "The uploaded file does not look like a ZIP archive (detected: {$sniffedType}).", null, 422);
            }

            $result = $this->importer->import($tempPath, (string) $currentUser['username']);
        } catch (InstanceImportException $e) {
            return $this->renderForm($response, $currentUser, $e->getMessage(), null, 422);
        } finally {
            @unlink($tempPath);
        }

        $detail = sprintf(
            'users=%d circuits=%d versions=%d providers=%d locations=%d audit=%d',
            $result['counts']['users'],
            $result['counts']['circuits'],
            $result['counts']['circuit_versions'],
            $result['counts']['circuit_providers'],
            $result['counts']['locations'],
            $result['counts']['audit_log']
        );

        $rebindUser = $result['rebindUser'];
        if ($rebindUser === null) {
            // The archive has no active admin with this username: the
            // importing account no longer exists, so end the session.
            $this->auditLog->log(
                null,
                'instance_import',
                null,
                $detail . ' by_username=' . (string) $currentUser['username'],
                ClientIp::from($request)
            );
            $this->auth->logout();
            return (new \Slim\Psr7\Response())
                ->withHeader('Location', BasePath::url('/login'))
                ->withStatus(302);
        }

        // Rebind the session to the imported identity (matched by username;
        // the pre-import user row is gone). Regenerate the id on this
        // privilege change, as attemptLogin() does.
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $rebindUser['id'];
        $_SESSION['role'] = $rebindUser['role'];

        $this->auditLog->log((int) $rebindUser['id'], 'instance_import', null, $detail, ClientIp::from($request));

        return (new \Slim\Psr7\Response())
            ->withHeader('Location', BasePath::url('/admin/instance?imported=1'))
            ->withStatus(302);
    }

    private function sniffMimeType(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return '';
        }
        $type = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return $type;
    }

    /**
     * @param mixed $currentUser
     */
    private function renderForm(
        Response $response,
        $currentUser,
        ?string $error,
        ?string $success,
        int $status = 200
    ): Response {
        $html = View::render('layout', [
            'title' => 'Instance transfer',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/instance_transfer', [
                'csrfToken' => $this->csrf->getToken(),
                'error' => $error,
                'success' => $success,
                'currentUsername' => is_array($currentUser) ? (string) ($currentUser['username'] ?? '') : '',
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }
}
