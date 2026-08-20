<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueMessage;

final class PublishedExecution
{
    public function __construct(
        public readonly PublishedExecutionMode $mode,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $flow,
        public readonly string $bindingId,
        public readonly ?string $queueMessageId = null,
        public readonly ?string $transport = null,
        public readonly ?string $queue = null,
        public readonly ?DateTimeImmutable $availableAt = null,
        public readonly ?QueueJobState $status = null,
        public readonly ?DateTimeImmutable $startedAt = null,
        public readonly ?DateTimeImmutable $finishedAt = null,
        public readonly ?int $durationMs = null,
    ) {
    }

    public static function queued(QueueMessage $message, QueueEnqueueResult $result): self
    {
        return new self(
            PublishedExecutionMode::Queued,
            $message->messageId,
            $message->correlationId,
            $message->flow,
            $message->bindingId,
            $result->queueMessageId,
            $message->transport,
            $message->queue,
            $message->availableAt,
            QueueJobState::Pending,
        );
    }

    public static function sync(
        Envelope $envelope,
        string $bindingId,
        QueueJobState $status,
        ?DateTimeImmutable $startedAt = null,
        ?DateTimeImmutable $finishedAt = null,
        ?int $durationMs = null,
    ): self {
        return new self(
            PublishedExecutionMode::Sync,
            $envelope->messageId,
            $envelope->correlationId,
            $envelope->flow,
            $bindingId,
            status: $status,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationMs: $durationMs,
        );
    }
}
