<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerActivityState: string
{
    case Idle = 'idle';
    case Busy = 'busy';
}
