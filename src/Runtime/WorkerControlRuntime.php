<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Runtime;

use Wolfcharaa\MessageBus\Worker\WorkerControlCommandRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlInboxInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlNotifierInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlServiceInterface;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerStatusService;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusServiceInterface;

final class WorkerControlRuntime
{
    private readonly WorkerStatusServiceInterface $statusService;

    public function __construct(
        private readonly WorkerControlServiceInterface $controlService,
        private readonly WorkerControlCommandRepositoryInterface $commandRepository,
        private readonly WorkerDesiredStateRepositoryInterface $desiredStateRepository,
        private readonly WorkerRegistryInterface $workerRegistry,
        private readonly WorkerStatusRepositoryInterface $statusRepository,
        private readonly WorkerControlInboxInterface $inbox,
        private readonly ?WorkerControlNotifierInterface $notifier = null,
        ?WorkerStatusServiceInterface $statusService = null,
    ) {
        $this->statusService = $statusService ?? new DefaultWorkerStatusService($statusRepository);
    }

    public function controlService(): WorkerControlServiceInterface
    {
        return $this->controlService;
    }

    public function commandRepository(): WorkerControlCommandRepositoryInterface
    {
        return $this->commandRepository;
    }

    public function desiredStateRepository(): WorkerDesiredStateRepositoryInterface
    {
        return $this->desiredStateRepository;
    }

    public function workerRegistry(): WorkerRegistryInterface
    {
        return $this->workerRegistry;
    }

    public function statusRepository(): WorkerStatusRepositoryInterface
    {
        return $this->statusRepository;
    }

    public function statusService(): WorkerStatusServiceInterface
    {
        return $this->statusService;
    }

    public function inbox(): WorkerControlInboxInterface
    {
        return $this->inbox;
    }

    public function notifier(): ?WorkerControlNotifierInterface
    {
        return $this->notifier;
    }
}
