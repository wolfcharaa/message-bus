<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class PcntlAutoWorkerRunnerOptions
{
    public function __construct(
        public readonly int $maxWorkers = 2,
        public readonly ?int $maxMessages = null,
        public readonly ?int $maxRuntimeSeconds = null,
        public readonly ?int $idleTimeoutSeconds = null,
        public readonly bool $stopWhenEmpty = false,
        public readonly int $sleepWhenIdleMilliseconds = 500,
        public readonly ?int $memoryLimitBytes = null,
    ) {
    }
}
