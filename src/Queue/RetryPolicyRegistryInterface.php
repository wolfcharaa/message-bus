<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface RetryPolicyRegistryInterface
{
    public function get(string $key): RetryPolicy;
}
