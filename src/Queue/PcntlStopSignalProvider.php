<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class PcntlStopSignalProvider implements StopSignalProviderInterface
{
    private bool $stop = false;

    public function __construct()
    {
        if (!\extension_loaded('pcntl')) {
            return;
        }

        \pcntl_async_signals(true);
        \pcntl_signal(SIGTERM, fn (): bool => $this->stop = true);
        \pcntl_signal(SIGINT, fn (): bool => $this->stop = true);
        \pcntl_signal(SIGQUIT, fn (): bool => $this->stop = true);
    }

    public function shouldStop(): bool
    {
        return $this->stop;
    }
}
