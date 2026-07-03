<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Shared ownership rule: an editor may act on circuits they uploaded,
 * an admin may act on any circuit. A readonly user (or any unrecognized
 * role) may never edit/delete, even if they happen to own the circuit -
 * demoting a user to readonly must fully revoke edit rights. Used by both
 * circuit editing and status-setting endpoints so the rule only lives in
 * one place.
 */
final class CircuitAuthorization
{
    /**
     * @param array<string, mixed> $circuit
     * @param mixed $currentUser
     */
    public static function canEdit(array $circuit, $currentUser): bool
    {
        if (!is_array($currentUser)) {
            return false;
        }
        $role = $currentUser['role'] ?? null;
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'editor') {
            return (int) $currentUser['id'] === (int) $circuit['owner_id'];
        }
        return false;
    }
}
