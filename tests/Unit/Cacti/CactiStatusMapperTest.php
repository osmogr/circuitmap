<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Cacti;

use CircuitMap\Services\Cacti\CactiStatusMapper;
use PHPUnit\Framework\TestCase;

final class CactiStatusMapperTest extends TestCase
{
    public function testUpHostMapsToUp(): void
    {
        $this->assertSame('up', CactiStatusMapper::toStatus(['status' => 3, 'disabled' => false]));
    }

    public function testRecoveringHostMapsToDegraded(): void
    {
        $this->assertSame('degraded', CactiStatusMapper::toStatus(['status' => 2, 'disabled' => false]));
    }

    public function testDownHostMapsToDown(): void
    {
        $this->assertSame('down', CactiStatusMapper::toStatus(['status' => 1, 'disabled' => false]));
    }

    public function testNotMonitoredHostMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toStatus(['status' => 0, 'disabled' => false]));
    }

    public function testDisabledHostMapsToUnknownEvenWhenUp(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toStatus(['status' => 3, 'disabled' => true]));
    }

    public function testMissingHostMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toStatus(null));
    }

    public function testUnexpectedStatusCodeMapsToUnknown(): void
    {
        $this->assertSame('unknown', CactiStatusMapper::toStatus(['status' => 42, 'disabled' => false]));
    }
}
