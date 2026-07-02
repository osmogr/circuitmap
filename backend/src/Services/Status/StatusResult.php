<?php

declare(strict_types=1);

namespace CircuitMap\Services\Status;

final class StatusResult
{
    public function __construct(
        public readonly string $status,
        public readonly string $source,
        public readonly ?string $updatedAt
    ) {
    }
}
