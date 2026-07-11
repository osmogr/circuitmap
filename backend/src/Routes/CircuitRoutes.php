<?php

declare(strict_types=1);

namespace CircuitMap\Routes;

use CircuitMap\Controllers\CircuitController;
use CircuitMap\Controllers\LocationController;
use CircuitMap\Middleware\AuthGateMiddleware;
use CircuitMap\Middleware\CsrfMiddleware;
use CircuitMap\Middleware\RateLimitMiddleware;
use CircuitMap\Middleware\RoleMiddleware;
use CircuitMap\Services\RateLimit\RateLimiterService;
use CircuitMap\Support\Env;
use Slim\App;

final class CircuitRoutes
{
    /**
     * @param array<string, mixed> $services
     */
    public static function register(App $app, array $services): void
    {
        /** @var CircuitController $controller */
        $controller = $services['circuitController'];
        /** @var \CircuitMap\Controllers\EditController $editController */
        $editController = $services['editController'];
        /** @var \CircuitMap\Controllers\StatusController $statusController */
        $statusController = $services['statusController'];
        /** @var LocationController $locationController */
        $locationController = $services['locationController'];
        /** @var AuthGateMiddleware $authGate */
        $authGate = $services['authGateMiddleware'];
        /** @var CsrfMiddleware $csrfMiddleware */
        $csrfMiddleware = $services['csrfMiddleware'];
        /** @var RateLimiterService $rateLimiter */
        $rateLimiter = $services['rateLimiter'];

        $editorOrAdmin = new RoleMiddleware(['editor', 'admin']);

        $app->get('/upload', [$controller, 'showUploadForm'])->add($editorOrAdmin)->add($authGate);

        $app->post('/upload', [$controller, 'upload'])
            ->add($editorOrAdmin)
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'upload', 3600, 20, 'user'));

        $app->post('/upload/confirm-split', [$controller, 'confirmSplit'])
            ->add($editorOrAdmin)
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'upload', 3600, 20, 'user'));

        $app->get('/circuits/new', [$controller, 'showNewForm'])->add($editorOrAdmin)->add($authGate);

        $app->post('/circuits/new', [$controller, 'createBlank'])
            ->add($editorOrAdmin)
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'upload', 3600, 20, 'user'));

        $reportPage = $app->get('/circuits/report', [$controller, 'showReport']);
        $reportCsv = $app->get('/circuits/report.csv', [$controller, 'exportCsv']);

        $apiCircuits = $app->get('/api/circuits', [$controller, 'listJson']);
        $apiGeoJson = $app->get('/api/circuits/{uuid}/geojson', [$controller, 'geoJson']);
        $apiLocations = $app->get('/api/locations', [$locationController, 'listJson']);

        if (Env::getBool('REQUIRE_AUTH_FOR_VIEW', false)) {
            $reportPage->add($authGate);
            $reportCsv->add($authGate);
            $apiCircuits->add($authGate);
            $apiGeoJson->add($authGate);
            $apiLocations->add($authGate);
        }

        $app->get('/circuits/{uuid}/edit', [$editController, 'showEditForm'])->add($authGate);

        $app->put('/circuits/{uuid}', [$editController, 'update'])
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'edit', 3600, 60, 'user'));

        $app->delete('/circuits/{uuid}', [$controller, 'delete'])
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'edit', 3600, 60, 'user'));

        $app->get('/circuits/{uuid}/versions', [$editController, 'listVersions'])->add($authGate);

        $app->post('/circuits/{uuid}/revert/{version}', [$editController, 'revert'])
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'edit', 3600, 60, 'user'));

        $app->post('/circuits/{uuid}/status', [$statusController, 'setStatus'])
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'edit', 3600, 60, 'user'));

        $apiStatus = $app->get('/api/circuits/{uuid}/status', [$statusController, 'getStatus']);
        if (Env::getBool('REQUIRE_AUTH_FOR_VIEW', false)) {
            $apiStatus->add($authGate);
        }
    }
}
