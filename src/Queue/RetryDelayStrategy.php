<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface RetryDelayStrategy
{
    public function delaySeconds(int $attempt): int;
}
