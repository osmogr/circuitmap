<?php

declare(strict_types=1);

namespace CircuitMap\Routes;

use CircuitMap\Controllers\AdminController;
use CircuitMap\Controllers\CircuitProviderController;
use CircuitMap\Middleware\AuthGateMiddleware;
use CircuitMap\Middleware\CsrfMiddleware;
use CircuitMap\Middleware\RoleMiddleware;
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
        /** @var AuthGateMiddleware $authGate */
        $authGate = $services['authGateMiddleware'];
        /** @var CsrfMiddleware $csrfMiddleware */
        $csrfMiddleware = $services['csrfMiddleware'];

        $adminOnly = new RoleMiddleware('admin');

        $app->get('/admin/users', [$controller, 'showUsers'])->add($adminOnly)->add($authGate);

        $app->post('/admin/users', [$controller, 'createUser'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/users/{id}/role', [$controller, 'setRole'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/users/{id}/active', [$controller, 'setActive'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->get('/admin/audit-log', [$controller, 'showAuditLog'])->add($adminOnly)->add($authGate);

        $app->get('/admin/providers', [$providerController, 'showProviders'])->add($adminOnly)->add($authGate);

        $app->post('/admin/providers', [$providerController, 'createProvider'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/providers/{id}', [$providerController, 'updateProvider'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);

        $app->post('/admin/providers/{id}/active', [$providerController, 'setActive'])
            ->add($adminOnly)->add($authGate)->add($csrfMiddleware);
    }
}
