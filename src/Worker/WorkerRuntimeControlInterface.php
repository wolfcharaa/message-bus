<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerRuntimeControlInterface
{
    public function heartbeat(): void;

    public function isCancellationRequested(): bool;
}
