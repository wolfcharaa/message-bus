<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerControlInboxInterface
{
    public function receive(WorkerIdentity $identity, WorkerControlCursor $cursor): WorkerControlBatch;
}
