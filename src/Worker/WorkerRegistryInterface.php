<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

interface WorkerRegistryInterface
{
    public function registerWorker(WorkerInstance $worker): void;

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void;

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void;

    public function registerChild(WorkerChildInstance $child): void;

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void;

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void;
}
