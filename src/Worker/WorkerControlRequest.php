<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerControlRequest
{
    public function __construct(
        public readonly ?string $createdBy = null,
        public readonly string $source = 'unknown',
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $override = false,
    ) {
    }
}
