<?php

declare(strict_types=1);

namespace CircuitMap\Routes;

use CircuitMap\Controllers\AdminController;
use CircuitMap\Controllers\CircuitProviderController;
use CircuitMap\Controllers\LocationController;
use CircuitMap\Middleware\AuthGateMiddleware;
use CircuitMap\Middleware\CsrfMiddleware;
use CircuitMap\Middleware\RateLimitMiddleware;
use CircuitMap\Middleware\RoleMiddleware;
use CircuitMap\Services\RateLimit\RateLimiterService;
use Slim\App;

final class AdminRoutes
{
    /**
     * @param array<string, mixed> $services
     */
    public static function register(App $app, array $services): void
    {
        /** @var AdminController $controller */
        $controller = $services['adminController'];
        /** @var CircuitProviderController $providerController */
        $providerController = $services['circuitProviderController'];
        /** @var LocationController $locationController */
        $locationController = $services['locationController'];
        /** @var AuthGateMiddleware $authGate */
        $authGate = $services['authGateMiddleware'];
        /** @var CsrfMiddleware $csrfMiddleware */
        $csrfMiddleware = $services['csrfMiddleware'];
        /** @var RateLimiterService $rateLimiter */
        $rateLimiter = $services['rateLimiter'];

        $adminOnly = new RoleMiddleware(['admin']);
        $editorOrAdmin = new RoleMiddleware(['editor', 'admin']);

        $app->get('/admin/users', [$controller, 'showUsers'])->add($adminOnly)->add($authGate);

        $app->post('/admin/users', [$controller, 'createUser'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/users/{id}/role', [$controller, 'setRole'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/users/{id}/active', [$controller, 'setActive'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->get('/admin/audit-log', [$controller, 'showAuditLog'])->add($adminOnly)->add($authGate);

        $app->get('/admin/providers', [$providerController, 'showProviders'])->add($editorOrAdmin)->add($authGate);

        $app->post('/admin/providers', [$providerController, 'createProvider'])
            ->add($editorOrAdmin)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/providers/{id}', [$providerController, 'updateProvider'])
            ->add($editorOrAdmin)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/providers/{id}/active', [$providerController, 'setActive'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->get('/admin/locations', [$locationController, 'showLocations'])->add($editorOrAdmin)->add($authGate);

        $app->post('/admin/locations/geocode', [$locationController, 'geocodeAddress'])
            ->add($editorOrAdmin)
            ->add($authGate)
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'geocode', 60, 5, 'user'));

        $app->post('/admin/locations', [$locationController, 'createLocation'])
            ->add($editorOrAdmin)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/locations/{id}', [$locationController, 'updateLocation'])
            ->add($editorOrAdmin)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/locations/{id}/active', [$locationController, 'setActive'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);
    }
}
