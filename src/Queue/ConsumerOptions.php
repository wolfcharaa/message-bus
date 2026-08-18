<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class ConsumerOptions
{
    public function __construct(
        public readonly string $transport,
        public readonly string $queue,
        public readonly int $timeoutSeconds = 5,
        public readonly int $limit = 1,
    ) {
    }
}
