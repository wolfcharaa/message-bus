<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use RuntimeException;

final class QueueEnqueueFailed extends RuntimeException
{
    public function __construct(
        public readonly QueueMessage $queueMessage,
        \Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), $previous->getCode(), $previous);
    }
}
