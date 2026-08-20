<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use DateTimeImmutable;

final class QueueJobStatus
{
    public function __construct(
        public readonly string $queueMessageId,
        public readonly QueueJobState $status,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $flow,
        public readonly string $bindingId,
        public readonly string $transport,
        public readonly string $queue,
        public readonly int $attempts,
        public readonly int $maxAttempts,
        public readonly DateTimeImmutable $availableAt,
        public readonly ?DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly ?string $lastError = null,
    ) {
    }
}
