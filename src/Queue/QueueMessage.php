<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;

final class QueueMessage
{
    public function __construct(
        public readonly string $transport,
        public readonly string $queue,
        public readonly SerializedEnvelope $envelope,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $flow,
        public readonly string $bindingId,
        public readonly DateTimeImmutable $availableAt,
        public readonly int $priority = 0,
    ) {
    }
}
