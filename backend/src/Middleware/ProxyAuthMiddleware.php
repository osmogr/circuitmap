<?php

declare(strict_types=1);

namespace CircuitMap\Middleware;

use CircuitMap\Services\Auth\AuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Trusts a header set by an upstream reverse proxy (e.g. oauth2-proxy,
 * an SSO gateway) as proof of identity, the same way ClientIp trusts
 * X-Forwarded-For. This is ONLY safe when every request that reaches this
 * application has already passed through that proxy, and the proxy
 * strips/overwrites any client-supplied copy of the header before
 * forwarding. If this app is ever reachable directly (bypassing the
 * proxy) while PROXY_AUTH_ENABLED=true, any client can set this header
 * themselves and log in as an arbitrary, possibly-nonexistent user -
 * auto-provisioning them if they don't already exist. Do not enable this
 * outside a deployment where the operator controls the network path and
 * the proxy's header-stripping behavior.
 *
 * Runs after SessionMiddleware (added to the app before it, so it
 * executes later in the request lifecycle - see App::create()), and
 * before route-specific middleware like AuthGateMiddleware, so a session
 * is available to write to and currentUser() reflects the proxy identity
 * for the rest of the request.
 */
final class ProxyAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly string $headerName,
        private readonly string $defaultRole
    ) {
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        $username = trim($request->getHeaderLine($this->headerName));
        if ($username !== '') {
            $this->auth->syncFromProxyHeader($username, $this->defaultRole);
        }

        return $handler->handle($request);
    }
}
