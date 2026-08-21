<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;

interface PostgresQueueStorageInterface extends QueueStatusRepositoryInterface, QueueJobControlInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult;

    /**
     * @param iterable<QueueMessage> $messages
     */
    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult;

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage;

    public function ack(ReceivedQueueMessage $message): void;

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void;

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void;

    public function cancelReceived(ReceivedQueueMessage $message, \Throwable $reason): void;

    public function recoverStale(ConsumerOptions $options): int;
}
