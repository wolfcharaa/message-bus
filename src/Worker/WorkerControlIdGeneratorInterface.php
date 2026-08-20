<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerControlIdGeneratorInterface
{
    public function nextCommandId(WorkerControlCommandType $type): string;

    public function nextDesiredStateId(WorkerControlCommand $command): string;
}
