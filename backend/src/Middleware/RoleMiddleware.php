<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Support\Response as ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Must run after AuthGateMiddleware, which populates the "currentUser"
 * request attribute.
 */
final class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly string $requiredRole)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $user = $request->getAttribute('currentUser');
        if (!is_array($user) || ($user['role'] ?? null) !== $this->requiredRole) {
            return ResponseHelper::json(['error' => 'Forbidden'], 403);
        }

        return $handler->handle($request);
    }
}
