<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerChildInstance
{
    public function __construct(
        public readonly string $childInstanceId,
        public readonly string $parentWorkerInstanceId,
        public readonly int $pid,
        public readonly WorkerChildState $state,
        public readonly DateTimeImmutable $startedAt,
        public readonly DateTimeImmutable $heartbeatAt,
        public readonly ?string $queueMessageId = null,
        public readonly ?string $messageId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $bindingId = null,
        public readonly ?DateTimeImmutable $finishedAt = null,
        public readonly ?string $failureMessage = null,
    ) {
    }
}
