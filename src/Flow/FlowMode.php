<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow;

enum FlowMode: string
{
    case Sync = 'sync';
    case Async = 'async';
}
