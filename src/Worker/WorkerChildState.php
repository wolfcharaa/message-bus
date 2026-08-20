<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerChildState: string
{
    case Starting = 'starting';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Retrying = 'retrying';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Killed = 'killed';
}
