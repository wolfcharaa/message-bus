<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface MessageConsumerInterface
{
    public function next(ConsumerOptions $options): ?ReceivedQueueMessage;

    public function ack(ReceivedQueueMessage $message): void;

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void;

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void;
}
