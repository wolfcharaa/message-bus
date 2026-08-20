<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface QueueStatusRepositoryInterface
{
    public function get(string $queueMessageId): ?QueueJobStatus;

    /** @return list<QueueJobStatus> */
    public function listByMessageId(string $messageId): array;

    /** @return list<QueueJobStatus> */
    public function listByCorrelationId(string $correlationId): array;
}
