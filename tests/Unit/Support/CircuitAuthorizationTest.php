<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Support;

use CircuitMap\Support\CircuitAuthorization;
use PHPUnit\Framework\TestCase;

final class CircuitAuthorizationTest extends TestCase
{
    private function circuit(int $ownerId): array
    {
        return ['id' => 1, 'owner_id' => $ownerId];
    }

    public function testAdminCanEditAnyCircuit(): void
    {
        $admin = ['id' => 99, 'role' => 'admin'];
        $this->assertTrue(CircuitAuthorization::canEdit($this->circuit(1), $admin));
    }

    public function testEditorCanEditOwnCircuit(): void
    {
        $editor = ['id' => 1, 'role' => 'editor'];
        $this->assertTrue(CircuitAuthorization::canEdit($this->circuit(1), $editor));
    }

    public function testEditorCannotEditOthersCircuit(): void
    {
        $editor = ['id' => 2, 'role' => 'editor'];
        $this->assertFalse(CircuitAuthorization::canEdit($this->circuit(1), $editor));
    }

    public function testReadonlyCannotEditOwnCircuitEvenIfOwner(): void
    {
        $readonly = ['id' => 1, 'role' => 'readonly'];
        $this->assertFalse(CircuitAuthorization::canEdit($this->circuit(1), $readonly));
    }

    public function testUnknownRoleCannotEdit(): void
    {
        $unknown = ['id' => 1, 'role' => 'something-else'];
        $this->assertFalse(CircuitAuthorization::canEdit($this->circuit(1), $unknown));
    }

    public function testNonArrayCurrentUserCannotEdit(): void
    {
        $this->assertFalse(CircuitAuthorization::canEdit($this->circuit(1), null));
    }
}
