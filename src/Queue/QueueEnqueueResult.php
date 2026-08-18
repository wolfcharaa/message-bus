<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueEnqueueResult
{
    public function __construct(public readonly string $queueMessageId)
    {
    }
}
