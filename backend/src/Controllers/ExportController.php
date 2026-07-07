<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Services\Kml\KmlExportService;
use CircuitMap\Support\ClientIp;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ExportController
{
    public function __construct(
        private readonly KmlExportService $exporter,
        private readonly AuditLogRepository $auditLog
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function exportCircuits(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $format = ($args['format'] ?? 'kml') === 'kmz' ? 'kmz' : 'kml';

        if ($format === 'kmz') {
            $result = $this->exporter->buildKmz();
            $body = $result['kmz'];
            $contentType = 'application/vnd.google-earth.kmz';
        } else {
            $result = $this->exporter->buildKml();
            $body = $result['kml'];
            $contentType = 'application/vnd.google-earth.kml+xml';
        }

        $this->auditLog->log(
            (int) $currentUser['id'],
            'export',
            null,
            sprintf('format=%s circuits=%d skipped=%d', $format, $result['exported'], count($result['skipped'])),
            ClientIp::from($request)
        );

        $filename = 'circuitmap-export-' . gmdate('Ymd') . '.' . $format;
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string) strlen($body));
    }
}
