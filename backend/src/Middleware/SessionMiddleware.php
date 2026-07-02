<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Services\Auth\PdoSessionHandler;
use CircuitMap\Support\Database;
use CircuitMap\Support\Env;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

final class SessionMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_set_save_handler(new PdoSessionHandler(Database::connection()), true);
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => Env::getBool('COOKIE_SECURE', true),
            ]);
            session_name('circuitmap_session');
            session_start();
        }

        $response = $handler->handle($request);
        session_write_close();

        return $response;
    }
}
