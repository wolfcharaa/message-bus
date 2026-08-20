<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class NullStopSignalProvider implements StopSignalProviderInterface
{
    public function shouldStop(): bool
    {
        return false;
    }
}
