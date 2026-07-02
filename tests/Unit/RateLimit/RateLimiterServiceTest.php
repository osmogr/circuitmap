<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\RateLimit;

use CircuitMap\Services\RateLimit\RateLimiterService;
use CircuitMap\Tests\Support\DatabaseTestCase;

final class RateLimiterServiceTest extends DatabaseTestCase
{
    public function testAllowsUpToTheConfiguredLimit(): void
    {
        $limiter = new RateLimiterService($this->pdo);

        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue($limiter->attempt('test-bucket', 60, 5));
        }
    }

    public function testBlocksOnceLimitIsExceeded(): void
    {
        $limiter = new RateLimiterService($this->pdo);

        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('test-bucket', 60, 5);
        }

        $this->assertFalse($limiter->attempt('test-bucket', 60, 5));
    }

    public function testDifferentBucketsAreIsolated(): void
    {
        $limiter = new RateLimiterService($this->pdo);

        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('bucket-a', 60, 5);
        }

        $this->assertFalse($limiter->attempt('bucket-a', 60, 5));
        $this->assertTrue($limiter->attempt('bucket-b', 60, 5));
    }
}
