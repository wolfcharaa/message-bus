<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueWorkerRunResult
{
    public function __construct(
        public readonly int $handled = 0,
        public readonly int $succeeded = 0,
        public readonly int $retried = 0,
        public readonly int $rejected = 0,
        public readonly int $cancelled = 0,
    ) {
    }
}
