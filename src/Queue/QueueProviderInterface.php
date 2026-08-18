<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult;
}
