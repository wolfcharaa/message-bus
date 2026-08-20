<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerControlAcknowledgementState: string
{
    case Applied = 'applied';
    case Skipped = 'skipped';
    case Failed = 'failed';
    case Expired = 'expired';
}
