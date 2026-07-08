<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Cacti;

use CircuitMap\Services\Cacti\CactiStatusMapper;
use PHPUnit\Framework\TestCase;

final class CactiStatusMapperTest extends TestCase
{
    public function testUpHostMapsToUp(): void
    {
        $this->assertSame('up', CactiStatusMapper::toCircuitStatus(['status' => 3, 'disabled' => false]));
    }

    public function testRecoveringHostMapsToDegraded(): void
    {
        $this->assertSame('degraded', CactiStatusMapper::toCircuitStatus(['status' => 2, 'disabled' => false]));
    }

    public function testDownHostMapsToDown(): void
    {
        $this->assertSame('down', CactiStatusMapper::toCircuitStatus(['status' => 1, 'disabled' => false]));
    }

    public function testNotMonitoredHostMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toCircuitStatus(['status' => 0, 'disabled' => false]));
    }

    public function testDisabledHostMapsToUnknownEvenWhenUp(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toCircuitStatus(['status' => 3, 'disabled' => true]));
    }

    public function testMissingHostMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toCircuitStatus(null));
    }

    public function testUnexpectedStatusCodeMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toCircuitStatus(['status' => 42, 'disabled' => false]));
    }
}
