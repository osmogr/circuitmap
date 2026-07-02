<?php

declare(strict_types=1);

namespace CircuitMap\Support;

/**
 * Shared ownership rule: an editor may act on circuits they uploaded,
 * an admin may act on any circuit. Used by both circuit editing and
 * status-setting endpoints so the rule only lives in one place.
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
        return (int) $currentUser['id'] === (int) $circuit['owner_id'] || ($currentUser['role'] ?? null) === 'admin';
    }
}
