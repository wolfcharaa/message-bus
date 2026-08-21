<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerCliOutputWriterInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function write(
        string $level,
        string $event,
        string $message,
        array $context = [],
        WorkerCliOutputVerbosity $verbosity = WorkerCliOutputVerbosity::Normal,
    ): void;
}
