<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker\Postgres;

use DateTimeImmutable;
use PDO;
use Wolfcharaa\MessageBus\Postgres\OperationSafety;
use Wolfcharaa\MessageBus\Postgres\PdoConnectionProviderInterface;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryingExecutor;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlBatch;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerControlInboxInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class ResilientPostgresWorkerControlStorage implements
    WorkerControlCommandRepositoryInterface,
    WorkerDesiredStateRepositoryInterface,
    WorkerControlInboxInterface,
    WorkerRegistryInterface,
    WorkerStatusRepositoryInterface
{
    private readonly PostgresRetryingExecutor $executor;

    public function __construct(
        private readonly PdoConnectionProviderInterface $connectionProvider,
        private readonly WorkerControlTableDefinition $definition = new WorkerControlTableDefinition(),
        ?PostgresRetryingExecutor $executor = null,
    ) {
        $this->executor = $executor ?? new PostgresRetryingExecutor($connectionProvider);
    }

    public function append(WorkerControlCommand $command): WorkerControlCommandReceipt
    {
        return $this->executor->execute(
            'worker_control.append',
            OperationSafety::IdempotentWithUniqueKey,
            fn (PDO $pdo): WorkerControlCommandReceipt => $this->storage($pdo)->append($command),
            $command->idempotencyKey ?? $command->commandId,
        );
    }

    public function findById(string $commandId): ?WorkerControlCommand
    {
        return $this->executor->execute(
            'worker_control.find_by_id',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): ?WorkerControlCommand => $this->storage($pdo)->findById($commandId),
        );
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand
    {
        return $this->executor->execute(
            'worker_control.find_by_idempotency_key',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): ?WorkerControlCommand => $this->storage($pdo)->findByIdempotencyKey($idempotencyKey),
        );
    }

    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        return $this->executor->execute(
            'worker_control.pending_for',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->pendingFor($identity, $cursor),
        );
    }

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void
    {
        $this->executor->execute(
            'worker_control.acknowledge',
            OperationSafety::IdempotentWithUniqueKey,
            function (PDO $pdo) use ($acknowledgement): null {
                $this->storage($pdo)->acknowledge($acknowledgement);

                return null;
            },
            $acknowledgement->commandId . '|' . $acknowledgement->workerInstanceId,
        );
    }

    public function apply(WorkerDesiredState $state): void
    {
        $this->executor->execute(
            'worker_control.apply_desired_state',
            OperationSafety::IdempotentWithUniqueKey,
            function (PDO $pdo) use ($state): null {
                $this->storage($pdo)->apply($state);

                return null;
            },
            $state->desiredStateId,
        );
    }

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState
    {
        return $this->executor->execute(
            'worker_control.resolve_desired_state',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): ?WorkerDesiredState => $this->storage($pdo)->resolveFor($identity),
        );
    }

    public function list(WorkerTarget $target): array
    {
        return $this->executor->execute(
            'worker_control.list_desired_states',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->list($target),
        );
    }

    public function receive(WorkerIdentity $identity, WorkerControlCursor $cursor): WorkerControlBatch
    {
        return $this->executor->execute(
            'worker_control.receive',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): WorkerControlBatch => $this->storage($pdo)->receive($identity, $cursor),
        );
    }

    public function registerWorker(WorkerInstance $worker): void
    {
        $this->executor->execute(
            'worker_control.register_worker',
            OperationSafety::IdempotentWithUniqueKey,
            function (PDO $pdo) use ($worker): null {
                $this->storage($pdo)->registerWorker($worker);

                return null;
            },
            $worker->identity->workerInstanceId,
        );
    }

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void {
        $this->executor->execute(
            'worker_control.heartbeat_worker',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($workerInstanceId, $state, $activity, $heartbeatAt, $childrenCount, $lastCommandId): null {
                $this->storage($pdo)->heartbeatWorker(
                    $workerInstanceId,
                    $state,
                    $activity,
                    $heartbeatAt,
                    $childrenCount,
                    $lastCommandId,
                );

                return null;
            },
        );
    }

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void {
        $this->executor->execute(
            'worker_control.stop_worker',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($workerInstanceId, $state, $stoppedAt, $failureMessage): null {
                $this->storage($pdo)->stopWorker($workerInstanceId, $state, $stoppedAt, $failureMessage);

                return null;
            },
        );
    }

    public function registerChild(WorkerChildInstance $child): void
    {
        $this->executor->execute(
            'worker_control.register_child',
            OperationSafety::IdempotentWithUniqueKey,
            function (PDO $pdo) use ($child): null {
                $this->storage($pdo)->registerChild($child);

                return null;
            },
            $child->childInstanceId,
        );
    }

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void
    {
        $this->executor->execute(
            'worker_control.heartbeat_child',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($childInstanceId, $state, $heartbeatAt): null {
                $this->storage($pdo)->heartbeatChild($childInstanceId, $state, $heartbeatAt);

                return null;
            },
        );
    }

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void {
        $this->executor->execute(
            'worker_control.finish_child',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($childInstanceId, $state, $finishedAt, $failureMessage): null {
                $this->storage($pdo)->finishChild($childInstanceId, $state, $finishedAt, $failureMessage);

                return null;
            },
        );
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        return $this->executor->execute(
            'worker_control.get_worker',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): ?WorkerInstance => $this->storage($pdo)->getWorker($workerInstanceId),
        );
    }

    public function listWorkers(WorkerTarget $target): array
    {
        return $this->executor->execute(
            'worker_control.list_workers',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->listWorkers($target),
        );
    }

    public function listChildren(string $workerInstanceId): array
    {
        return $this->executor->execute(
            'worker_control.list_children',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->listChildren($workerInstanceId),
        );
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        return $this->executor->execute(
            'worker_control.acknowledgements_for_command',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->acknowledgementsForCommand($commandId),
        );
    }

    private function storage(PDO $pdo): PostgresWorkerControlStorage
    {
        return new PostgresWorkerControlStorage($pdo, $this->definition);
    }
}
