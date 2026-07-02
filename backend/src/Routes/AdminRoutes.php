<?php

declare(strict_types=1);

namespace CircuitMap\Routes;

use CircuitMap\Controllers\AdminController;
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
    }
}
