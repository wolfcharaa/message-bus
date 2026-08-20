<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use DateTimeImmutable;

final class QueueEnqueueResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $queueMessageId,
        public readonly ?string $backendId = null,
        public readonly ?QueueJobState $status = null,
        public readonly ?DateTimeImmutable $createdAt = null,
        public readonly array $metadata = [],
    ) {
    }
}
