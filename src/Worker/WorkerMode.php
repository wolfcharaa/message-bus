<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerMode: string
{
    case Single = 'single';
    case Auto = 'auto';
}
