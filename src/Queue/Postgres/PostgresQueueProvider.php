<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use Wolfcharaa\MessageBus\Queue\BatchQueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;

final class PostgresQueueProvider implements BatchQueueProviderInterface
{
    public function __construct(private readonly PostgresQueueStorage $storage)
    {
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        return $this->storage->enqueue($message);
    }

    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        return $this->storage->enqueueMany($messages);
    }
}
