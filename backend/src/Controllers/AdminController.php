<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\UserRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AdminController
{
    private const VALID_ROLES = ['editor', 'admin'];

    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogRepository $auditLog,
        private readonly CsrfService $csrf
    ) {
    }

    public function showUsers(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $html = View::render('layout', [
            'title' => 'Manage users',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/users', [
                'csrfToken' => $this->csrf->getToken(),
                'users' => $this->users->listAll(),
                'currentUser' => $currentUser,
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createUser(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $username = is_string($body['username'] ?? null) ? trim($body['username']) : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $role = is_string($body['role'] ?? null) ? $body['role'] : '';

        $error = $this->validateNewUser($username, $password, $role);
        if ($error !== null) {
            return $this->renderUsersPage($response, $currentUser, $error, 422);
        }

        if ($this->users->findByUsername($username) !== null) {
            return $this->renderUsersPage($response, $currentUser, 'That username is already registered.', 422);
        }

        $newUserId = $this->users->create($username, password_hash($password, PASSWORD_DEFAULT), $role);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'user_create',
            null,
            "created_user_id={$newUserId} role={$role}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/users'))->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     */
    public function setRole(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $targetId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $role = is_string($body['role'] ?? null) ? $body['role'] : '';

        if (!in_array($role, self::VALID_ROLES, true) || $this->users->findById($targetId) === null) {
            return $this->renderUsersPage($response, $currentUser, 'Invalid role change request.', 422);
        }

        // An admin can't demote themselves via this form; that would risk
        // locking every admin out with no one left to reverse it. Use
        // another admin account, or the INITIAL_ADMIN_USERNAME bootstrap path
        // against a fresh database, to change the last admin's role.
        if ($targetId === (int) $currentUser['id'] && $role !== 'admin') {
            return $this->renderUsersPage($response, $currentUser, 'You cannot remove your own admin role.', 422);
        }

        $this->users->setRole($targetId, $role);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'role_change',
            null,
            "target_user_id={$targetId} new_role={$role}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/users'))->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     */
    public function setActive(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $targetId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $active = ($body['active'] ?? '') === '1';

        if ($this->users->findById($targetId) === null) {
            return $this->renderUsersPage($response, $currentUser, 'User not found.', 404);
        }

        if ($targetId === (int) $currentUser['id'] && !$active) {
            return $this->renderUsersPage($response, $currentUser, 'You cannot deactivate your own account.', 422);
        }

        $this->users->setActive($targetId, $active);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'role_change',
            null,
            'target_user_id=' . $targetId . ' active=' . ($active ? '1' : '0'),
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/users'))->withStatus(302);
    }

    public function showAuditLog(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $html = View::render('layout', [
            'title' => 'Audit log',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/audit_log', [
                'entries' => $this->auditLog->recent(200),
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param mixed $currentUser
     */
    private function renderUsersPage(Response $response, $currentUser, string $error, int $status): Response
    {
        $html = View::render('layout', [
            'title' => 'Manage users',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/users', [
                'csrfToken' => $this->csrf->getToken(),
                'users' => $this->users->listAll(),
                'currentUser' => $currentUser,
                'error' => $error,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }

    private function validateNewUser(string $username, string $password, string $role): ?string
    {
        if ($username === '' || strlen($username) > 190) {
            return 'A valid username is required.';
        }
        if (strlen($password) < 12) {
            return 'Password must be at least 12 characters.';
        }
        if (!in_array($role, self::VALID_ROLES, true)) {
            return 'Invalid role.';
        }
        return null;
    }
}
