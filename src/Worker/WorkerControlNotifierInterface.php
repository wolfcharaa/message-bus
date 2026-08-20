<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerControlNotifierInterface
{
    public function waitForControlSignal(int $timeoutMilliseconds): void;
}
