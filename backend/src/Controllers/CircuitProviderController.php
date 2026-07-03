<?php

declare(strict_types=1);

namespace CircuitMap\Controllers;

use CircuitMap\Models\AuditLogRepository;
use CircuitMap\Models\CircuitProviderRepository;
use CircuitMap\Services\Auth\CsrfService;
use CircuitMap\Support\BasePath;
use CircuitMap\Support\ClientIp;
use CircuitMap\Support\Response as ResponseHelper;
use CircuitMap\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class CircuitProviderController
{
    public function __construct(
        private readonly CircuitProviderRepository $providers,
        private readonly AuditLogRepository $auditLog,
        private readonly CsrfService $csrf
    ) {
    }

    public function showProviders(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $html = View::render('layout', [
            'title' => 'Manage circuit providers',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/providers', [
                'csrfToken' => $this->csrf->getToken(),
                'providers' => $this->providers->listAll(),
                'error' => null,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function createProvider(Request $request, Response $response): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $techSupportNumber = $this->nullableTrim($body['tech_support_number'] ?? null);
        $accountId = $this->nullableTrim($body['account_id'] ?? null);
        $localRepContact = $this->nullableTrim($body['local_rep_contact'] ?? null);

        if ($name === '') {
            return $this->renderProvidersPage($response, $currentUser, 'A provider name is required.', 422);
        }
        if ($this->providers->findByName($name) !== null) {
            return $this->renderProvidersPage($response, $currentUser, 'That provider name already exists.', 422);
        }

        $newProviderId = $this->providers->insert($name, $techSupportNumber, $accountId, $localRepContact);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'provider_create',
            null,
            "created_provider_id={$newProviderId} name={$name}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/providers'))->withStatus(302);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateProvider(Request $request, Response $response, array $args): Response
    {
        $currentUser = $request->getAttribute('currentUser');
        $targetId = (int) ($args['id'] ?? 0);
        $body = (array) $request->getParsedBody();
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        $techSupportNumber = $this->nullableTrim($body['tech_support_number'] ?? null);
        $accountId = $this->nullableTrim($body['account_id'] ?? null);
        $localRepContact = $this->nullableTrim($body['local_rep_contact'] ?? null);

        if ($this->providers->findById($targetId) === null) {
            return $this->renderProvidersPage($response, $currentUser, 'Provider not found.', 404);
        }
        if ($name === '') {
            return $this->renderProvidersPage($response, $currentUser, 'A provider name is required.', 422);
        }
        $existing = $this->providers->findByName($name);
        if ($existing !== null && (int) $existing['id'] !== $targetId) {
            return $this->renderProvidersPage($response, $currentUser, 'That provider name already exists.', 422);
        }

        $this->providers->update($targetId, $name, $techSupportNumber, $accountId, $localRepContact);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'provider_update',
            null,
            "target_provider_id={$targetId}",
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/providers'))->withStatus(302);
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

        if ($this->providers->findById($targetId) === null) {
            return $this->renderProvidersPage($response, $currentUser, 'Provider not found.', 404);
        }

        $this->providers->setActive($targetId, $active);
        $this->auditLog->log(
            (int) $currentUser['id'],
            'provider_update',
            null,
            'target_provider_id=' . $targetId . ' active=' . ($active ? '1' : '0'),
            ClientIp::from($request)
        );

        return (new \Slim\Psr7\Response())->withHeader('Location', BasePath::url('/admin/providers'))->withStatus(302);
    }

    /**
     * @param mixed $value
     */
    private function nullableTrim($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param mixed $currentUser
     */
    private function renderProvidersPage(Response $response, $currentUser, string $error, int $status): Response
    {
        $html = View::render('layout', [
            'title' => 'Manage circuit providers',
            'csrfToken' => $this->csrf->getToken(),
            'currentUser' => $currentUser,
            'content' => View::render('admin/providers', [
                'csrfToken' => $this->csrf->getToken(),
                'providers' => $this->providers->listAll(),
                'error' => $error,
            ]),
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')->withStatus($status);
    }
}
