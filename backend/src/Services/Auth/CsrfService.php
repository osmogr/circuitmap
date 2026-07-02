<?php

declare(strict_types=1);

namespace CircuitMap\Services\Auth;

final class CsrfService
{
    private const SESSION_KEY = 'csrf_token';

    public function getToken(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        }
        return $_SESSION[self::SESSION_KEY];
    }

    public function verify(?string $submittedToken): bool
    {
        $sessionToken = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($sessionToken) || !is_string($submittedToken) || $submittedToken === '') {
            return false;
        }
        return hash_equals($sessionToken, $submittedToken);
    }
}
