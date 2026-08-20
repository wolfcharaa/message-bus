<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface BatchQueueProviderInterface extends QueueProviderInterface
{
    /**
     * @param iterable<QueueMessage> $messages
     */
    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult;
}
