<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

enum PublishedExecutionMode: string
{
    case Queued = 'queued';
    case Sync = 'sync';
}
