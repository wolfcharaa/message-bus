<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerControlCursor
{
    public function __construct(public readonly ?string $lastCommandId = null)
    {
    }
}
