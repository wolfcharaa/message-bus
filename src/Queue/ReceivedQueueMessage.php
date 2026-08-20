<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class ReceivedQueueMessage
{
    public function __construct(
        public readonly string $queueMessageId,
        public readonly QueueMessage $message,
        public readonly int $attempts = 0,
        public readonly mixed $raw = null,
    ) {
    }
}
