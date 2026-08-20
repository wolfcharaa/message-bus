<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface QueueJobControlInterface
{
    public function cancel(string $queueMessageId): void;

    public function requestCancellation(string $queueMessageId): void;
}
