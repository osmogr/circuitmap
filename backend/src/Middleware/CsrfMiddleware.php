<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\Response as ResponseHelper;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Apply to route groups that mutate state (POST/PUT/PATCH/DELETE). Not
 * applied globally, since GET requests never change state and forcing a
 * token on them would break plain links/bookmarks.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly CsrfService $csrf)
    {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $token = $request->getHeaderLine('X-CSRF-Token');
        if ($token === '') {
            $body = $request->getParsedBody();
            $token = is_array($body) ? (string) ($body['csrf_token'] ?? '') : '';
        }

        if (!$this->csrf->verify($token)) {
            return ResponseHelper::json(['error' => 'Invalid or missing CSRF token'], 403);
        }

        return $handler->handle($request);
    }
}
