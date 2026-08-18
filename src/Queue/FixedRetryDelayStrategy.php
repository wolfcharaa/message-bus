<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class FixedRetryDelayStrategy implements RetryDelayStrategy
{
    public function __construct(private readonly int $delaySeconds)
    {
    }

    public function delaySeconds(int $attempt): int
    {
        return $this->delaySeconds;
    }
}
