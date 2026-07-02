<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Integration;

use CircuitMap\Middleware\ProxyAuthMiddleware;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Tests\Support\DatabaseTestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Exercises AuthService::syncFromProxyHeader() and ProxyAuthMiddleware
 * directly against a real DB, same scope/spirit as the other flow tests.
 * $_SESSION is manipulated as a plain array without starting a real PHP
 * session (mirroring how it behaves under CLI outside the HTTP stack);
 * SessionMiddleware guarantees a real active session in production, and
 * session-id regeneration itself is exercised by manual verification
 * against the running container, same as the rest of the auth flow.
 */
final class ProxyAuthFlowTest extends DatabaseTestCase
{
    private AuthService $auth;
    private UserRepository $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->users = new UserRepository($this->pdo);
        $this->auth = new AuthService($this->users);
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testAutoProvisionsNewUserWithDefaultRole(): void
    {
        $this->auth->syncFromProxyHeader('newperson', 'editor');

        $user = $this->users->findByUsername('newperson');
        $this->assertNotNull($user);
        $this->assertSame('editor', $user['role']);
        $this->assertSame((int) $user['id'], $_SESSION['user_id']);
    }

    public function testReusesExistingUserInsteadOfDuplicating(): void
    {
        $existingId = $this->createUser('existing', 'admin');

        $this->auth->syncFromProxyHeader('existing', 'editor');

        $this->assertSame($existingId, $_SESSION['user_id']);
        $this->assertSame('admin', $_SESSION['role']);
        $this->assertCount(1, $this->users->listAll());
    }

    public function testDeactivatedUserIsNotLoggedIn(): void
    {
        $id = $this->createUser('inactive');
        $this->users->setActive($id, false);

        $this->auth->syncFromProxyHeader('inactive', 'editor');

        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testMiddlewareIgnoresRequestsWithoutTheHeader(): void
    {
        $middleware = new ProxyAuthMiddleware($this->auth, 'REMOTE_USER', 'editor');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $handlerCalled = false;

        $middleware->process($request, new class ($handlerCalled) implements \Psr\Http\Server\RequestHandlerInterface {
            public function __construct(private bool &$called)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                $this->called = true;
                return (new ResponseFactory())->createResponse();
            }
        });

        $this->assertTrue($handlerCalled);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testMiddlewareLogsInFromHeader(): void
    {
        $middleware = new ProxyAuthMiddleware($this->auth, 'REMOTE_USER', 'editor');
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/')
            ->withHeader('REMOTE_USER', 'proxieduser');

        $middleware->process($request, new class implements \Psr\Http\Server\RequestHandlerInterface {
            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return (new ResponseFactory())->createResponse();
            }
        });

        $user = $this->users->findByUsername('proxieduser');
        $this->assertNotNull($user);
        $this->assertSame((int) $user['id'], $_SESSION['user_id']);
    }
}
