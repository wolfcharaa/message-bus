<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

interface PipelineInterface
{
    public function continue(): mixed;
}
