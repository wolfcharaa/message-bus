<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

interface HandlerExecutionStrategyInterface
{
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface;
}
