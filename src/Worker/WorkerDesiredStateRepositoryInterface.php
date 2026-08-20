<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerDesiredStateRepositoryInterface
{
    public function apply(WorkerDesiredState $state): void;

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState;

    /**
     * @return list<WorkerDesiredState>
     */
    public function list(WorkerTarget $target): array;
}
