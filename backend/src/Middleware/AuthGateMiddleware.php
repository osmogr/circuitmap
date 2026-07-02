<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Support\Response as ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

final class AuthGateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $this->auth->currentUser();
        if ($user === null) {
            return ResponseHelper::json(['error' => 'Authentication required'], 401);
        }

        return $handler->handle($request->withAttribute('currentUser', $user));
    }
}
