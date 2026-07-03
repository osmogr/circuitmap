<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Middleware;

use CircuitMap\Middleware\RoleMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RoleMiddlewareTest extends TestCase
{
    private function handler(bool &$called): RequestHandler
    {
        return new class ($called) implements RequestHandler {
            public function __construct(private bool &$called)
            {
            }

            public function handle(Request $request): Response
            {
                $this->called = true;
                return (new ResponseFactory())->createResponse(200);
            }
        };
    }

    public function testAllowsRequestWhenRoleIsInAllowedList(): void
    {
        $middleware = new RoleMiddleware(['editor', 'admin']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute('currentUser', ['id' => 1, 'role' => 'editor']);

        $called = false;
        $response = $middleware->process($request, $this->handler($called));

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAdminInEditorOrAdminGate(): void
    {
        $middleware = new RoleMiddleware(['editor', 'admin']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute('currentUser', ['id' => 1, 'role' => 'admin']);

        $called = false;
        $middleware->process($request, $this->handler($called));

        $this->assertTrue($called);
    }

    public function testRejectsReadonlyWith403InEditorOrAdminGate(): void
    {
        $middleware = new RoleMiddleware(['editor', 'admin']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withAttribute('currentUser', ['id' => 1, 'role' => 'readonly']);

        $called = false;
        $response = $middleware->process($request, $this->handler($called));

        $this->assertFalse($called);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testRejectsMissingCurrentUserAttributeWith403(): void
    {
        $middleware = new RoleMiddleware(['admin']);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $called = false;
        $response = $middleware->process($request, $this->handler($called));

        $this->assertFalse($called);
        $this->assertSame(403, $response->getStatusCode());
    }
}
