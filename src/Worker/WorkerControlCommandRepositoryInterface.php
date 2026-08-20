<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

interface WorkerControlCommandRepositoryInterface
{
    public function append(WorkerControlCommand $command): WorkerControlCommandReceipt;

    public function findById(string $commandId): ?WorkerControlCommand;

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand;

    /**
     * @return list<WorkerControlCommand>
     */
    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array;

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void;
}
