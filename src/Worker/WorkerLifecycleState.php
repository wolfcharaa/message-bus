<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerLifecycleState: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Paused = 'paused';
    case Draining = 'draining';
    case Stopping = 'stopping';
    case Killing = 'killing';
    case Restarting = 'restarting';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
