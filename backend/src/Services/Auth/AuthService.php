<?php

declare(strict_types=1);

namespace CircuitMap\Services\Auth;

use CircuitMap\Models\UserRepository;

final class AuthService
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * @return array<string, mixed>|null the user row on success, null on
     *   invalid credentials or a deactivated account. Deliberately does
     *   not distinguish "no such username" from "wrong password" in the
     *   return value, so callers cannot leak account existence.
     */
    public function attemptLogin(string $username, string $password): ?array
    {
        $user = $this->users->findByUsername($username);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        // Regenerate the session id on privilege change to prevent session
        // fixation (an attacker who fixed a pre-login session id gains
        // nothing once the id changes at login).
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];

        $this->users->updateLastLogin((int) $user['id']);

        return $user;
    }

    /**
     * Establishes a session from a username a trusted reverse proxy has
     * already authenticated, auto-provisioning the user on first sight.
     * Callers (ProxyAuthMiddleware) are solely responsible for ensuring
     * the username actually came from the proxy and not from the client;
     * this method trusts whatever string it is given.
     *
     * No-ops if the given username is already the current session identity,
     * so a proxy that sends the header on every request doesn't force a
     * session-id regeneration (and the DB write in updateLastLogin) on
     * every single hit.
     */
    public function syncFromProxyHeader(string $username, string $defaultRole): void
    {
        $user = $this->users->findByUsername($username);
        if ($user === null) {
            // The password is unusable on purpose: this account can only
            // ever be reached through the proxy header, never /login.
            $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
            $newId = $this->users->create($username, $passwordHash, $defaultRole);
            $user = $this->users->findById($newId);
        }

        if ($user === null || (int) $user['is_active'] !== 1) {
            return;
        }

        if (($_SESSION['user_id'] ?? null) === (int) $user['id']) {
            return;
        }

        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];

        $this->users->updateLastLogin((int) $user['id']);
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function isAuthenticated(): bool
    {
        return isset($_SESSION['user_id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        $user = $this->users->findById((int) $_SESSION['user_id']);
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }

        return $user;
    }
}
