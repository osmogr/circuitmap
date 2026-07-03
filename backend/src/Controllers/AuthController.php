<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Services\Auth\AuthService;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response as SlimResponse;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CsrfService $csrf,
        private readonly AuditLogRepository $auditLog
    ) {
    }

    public function showLogin(Request $request, Response $response): Response
    {
        if ($this->auth->isAuthenticated()) {
            return $response->withHeader('Location', BasePath::url('/'))->withStatus(302);
        }

        $html = View::render('layout', [
            'title' => 'Log in',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => null,
            'content' => View::render('login', [
                'csrfToken' => $this->csrf->getToken(),
                'error' => null,
            ]),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = ClientIp::from($request);

        $user = $username !== '' && $password !== '' ? $this->auth->attemptLogin($username, $password) : null;

        if ($user === null) {
            $this->auditLog->log(null, 'login_failure', null, "username={$username}", $ip);

            $html = View::render('layout', [
                'title' => 'Log in',
                'csrfToken' => $this->csrf->getToken(),
                'currentUser' => null,
                'content' => View::render('login', [
                    'csrfToken' => $this->csrf->getToken(),
                    'error' => 'Invalid username or password.',
                ]),
            ]);
            $response->getBody()->write($html);
            return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus(401);
        }

        $this->auditLog->log((int) $user['id'], 'login_success', null, null, $ip);

        return (new SlimResponse())->withHeader('Location', BasePath::url('/'))->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $user = $this->auth->currentUser();
        $this->auth->logout();

        if ($user !== null) {
            $this->auditLog->log((int) $user['id'], 'logout', null, null, ClientIp::from($request));
        }

        return $response->withHeader('Location', BasePath::url('/login'))->withStatus(302);
    }
}
