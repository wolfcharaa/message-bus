<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

enum QueueJobState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
