<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerDesiredStateType: string
{
    case Paused = 'paused';
    case Resumed = 'resumed';
}
