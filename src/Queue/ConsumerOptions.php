<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class ConsumerOptions
{
    /**
     * @param list<string> $flows
     * @param list<string> $bindingIds
     * @param list<string> $bindingPatterns
     */
    public function __construct(
        public readonly string $transport,
        public readonly string $queue,
        public readonly int $timeoutSeconds = 5,
        public readonly int $limit = 1,
        public readonly string $workerId = 'message-bus-worker',
        public readonly int $lockTtlSeconds = 300,
        public readonly array $flows = [],
        public readonly array $bindingIds = [],
        public readonly array $bindingPatterns = [],
    ) {
    }
}
