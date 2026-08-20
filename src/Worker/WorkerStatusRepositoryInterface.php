<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerStatusRepositoryInterface
{
    public function getWorker(string $workerInstanceId): ?WorkerInstance;

    /**
     * @return list<WorkerInstance>
     */
    public function listWorkers(WorkerTarget $target): array;

    /**
     * @return list<WorkerChildInstance>
     */
    public function listChildren(string $workerInstanceId): array;

    /**
     * @return list<WorkerControlAcknowledgement>
     */
    public function acknowledgementsForCommand(string $commandId): array;
}
