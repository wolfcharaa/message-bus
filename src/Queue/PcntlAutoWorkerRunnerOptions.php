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
        public readonly ?string $workerName = null,
        public readonly ?string $workerGroup = null,
        public readonly ?string $workerInstanceId = null,
        public readonly ?string $host = null,
        public readonly int $controlPollIntervalMilliseconds = 1000,
        public readonly int $heartbeatIntervalMilliseconds = 1000,
        public readonly int $forceKillTimeoutSeconds = 5,
        public readonly int $restartExitCode = 75,
        public readonly int $storageFailureBackoffMilliseconds = 1000,
        public readonly int $maxConsecutiveHeartbeatFailures = 3,
    ) {
    }
}
