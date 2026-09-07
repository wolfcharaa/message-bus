<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Interceptor;

interface PipelineInterface
{
    public function continue(): mixed;
}
