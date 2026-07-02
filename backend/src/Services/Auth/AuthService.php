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
     *   not distinguish "no such email" from "wrong password" in the
     *   return value, so callers cannot leak account existence.
     */
    public function attemptLogin(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail($email);
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

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
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
