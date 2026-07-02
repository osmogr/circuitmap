<?php

declare(strict_types=1);

namespace CircuitMap\Routes;

use CircuitMap\Controllers\AuthController;
use CircuitMap\Middleware\CsrfMiddleware;
use CircuitMap\Middleware\RateLimitMiddleware;
use CircuitMap\Services\RateLimit\RateLimiterService;
use Slim\App;

final class AuthRoutes
{
    /**
     * @param array<string, mixed> $services
     */
    public static function register(App $app, array $services): void
    {
        /** @var AuthController $controller */
        $controller = $services['authController'];
        /** @var CsrfMiddleware $csrfMiddleware */
        $csrfMiddleware = $services['csrfMiddleware'];
        /** @var RateLimiterService $rateLimiter */
        $rateLimiter = $services['rateLimiter'];

        $app->get('/login', [$controller, 'showLogin']);

        $app->post('/login', [$controller, 'login'])
            ->add($csrfMiddleware)
            ->add(new RateLimitMiddleware($rateLimiter, 'login', 300, 10, 'ip'));

        $app->post('/logout', [$controller, 'logout'])
            ->add($csrfMiddleware);
    }
}
