<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

interface StopSignalProviderInterface
{
    public function shouldStop(): bool;
}
