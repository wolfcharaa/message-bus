<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class DefaultWorkerStatusService implements WorkerStatusServiceInterface
{
    public function __construct(private readonly WorkerStatusRepositoryInterface $repository)
    {
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        return $this->repository->getWorker($workerInstanceId);
    }

    public function listWorkers(WorkerTarget $target): array
    {
        return $this->repository->listWorkers($target);
    }

    public function listChildren(string $workerInstanceId): array
    {
        return $this->repository->listChildren($workerInstanceId);
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        return $this->repository->acknowledgementsForCommand($commandId);
    }
}
