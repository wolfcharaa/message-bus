<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerControlBatch
{
    /**
     * @param list<WorkerControlCommand> $commands
     */
    public function __construct(
        public readonly array $commands,
        public readonly WorkerControlCursor $cursor,
        public readonly ?WorkerDesiredState $desiredState = null,
    ) {
    }
}
