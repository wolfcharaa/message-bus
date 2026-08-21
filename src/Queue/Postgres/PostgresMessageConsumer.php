<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;

final class PostgresMessageConsumer implements MessageConsumerInterface
{
    public function __construct(private readonly PostgresQueueStorageInterface $storage)
    {
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return $this->storage->next($options);
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        $this->storage->ack($message);
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->storage->retry($message, $reason);
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->storage->reject($message, $reason);
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->storage->cancelReceived($message, $reason);
    }
}
