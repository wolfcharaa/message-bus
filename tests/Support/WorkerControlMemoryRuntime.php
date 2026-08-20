<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests\Support;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Runtime\WorkerControlRuntime;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerControlService;
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
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class WorkerControlMemoryRuntime implements
    WorkerControlCommandRepositoryInterface,
    WorkerDesiredStateRepositoryInterface,
    WorkerControlInboxInterface,
    WorkerRegistryInterface,
    WorkerStatusRepositoryInterface
{
    /** @var list<WorkerControlCommand> */
    public array $commands = [];

    /** @var list<WorkerControlAcknowledgement> */
    public array $acknowledgements = [];

    /** @var list<WorkerDesiredState> */
    public array $desiredStates = [];

    /** @var array<string, WorkerInstance> */
    public array $workers = [];

    /** @var array<string, WorkerChildInstance> */
    public array $children = [];

    public function runtime(): WorkerControlRuntime
    {
        $service = new DefaultWorkerControlService($this, $this);

        return new WorkerControlRuntime($service, $this, $this, $this, $this, $this);
    }

    public function append(WorkerControlCommand $command): WorkerControlCommandReceipt
    {
        $this->commands[] = $command;

        return new WorkerControlCommandReceipt($command->commandId, $command->type, $command->target, $command->createdAt, $command->idempotencyKey);
    }

    public function findById(string $commandId): ?WorkerControlCommand
    {
        foreach ($this->commands as $command) {
            if ($command->commandId === $commandId) {
                return $command;
            }
        }

        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand
    {
        foreach ($this->commands as $command) {
            if ($command->idempotencyKey === $idempotencyKey) {
                return $command;
            }
        }

        return null;
    }

    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        $pending = [];
        $skip = $cursor->lastCommandId !== null;

        foreach ($this->commands as $command) {
            if ($skip) {
                if ($command->commandId === $cursor->lastCommandId) {
                    $skip = false;
                }

                continue;
            }

            if ($command->target->matches($identity)) {
                $pending[] = $command;
            }
        }

        return $pending;
    }

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void
    {
        $this->acknowledgements[] = $acknowledgement;
    }

    public function apply(WorkerDesiredState $state): void
    {
        $this->desiredStates[] = $state;
    }

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState
    {
        $states = \array_values(\array_filter(
            $this->desiredStates,
            static fn (WorkerDesiredState $state): bool => $state->matches($identity),
        ));

        \usort($states, static function (WorkerDesiredState $left, WorkerDesiredState $right): int {
            $score = $right->specificityScore() <=> $left->specificityScore();

            return $score !== 0 ? $score : $right->createdAt <=> $left->createdAt;
        });

        return $states[0] ?? null;
    }

    public function list(WorkerTarget $target): array
    {
        return $this->desiredStates;
    }

    public function receive(WorkerIdentity $identity, WorkerControlCursor $cursor): WorkerControlBatch
    {
        $last = $cursor->lastCommandId;
        foreach ($this->commands as $command) {
            $last = $command->commandId;
        }

        return new WorkerControlBatch($this->pendingFor($identity, $cursor), new WorkerControlCursor($last), $this->resolveFor($identity));
    }

    public function registerWorker(WorkerInstance $worker): void
    {
        $this->workers[$worker->identity->workerInstanceId] = $worker;
    }

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void {
        $current = $this->workers[$workerInstanceId] ?? null;
        if ($current === null) {
            return;
        }

        $this->workers[$workerInstanceId] = new WorkerInstance(
            $current->identity,
            $state,
            $activity,
            $heartbeatAt,
            $childrenCount,
            $lastCommandId ?? $current->lastCommandId,
            $lastCommandId === null ? $current->lastCommandAt : $heartbeatAt,
            $current->stoppedAt,
            $current->failureMessage,
        );
    }

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void {
        $current = $this->workers[$workerInstanceId] ?? null;
        if ($current === null) {
            return;
        }

        $this->workers[$workerInstanceId] = new WorkerInstance(
            $current->identity,
            $state,
            WorkerActivityState::Idle,
            $stoppedAt,
            0,
            $current->lastCommandId,
            $current->lastCommandAt,
            $stoppedAt,
            $failureMessage,
        );
    }

    public function registerChild(WorkerChildInstance $child): void
    {
        $this->children[$child->childInstanceId] = $child;
    }

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void
    {
        $current = $this->children[$childInstanceId] ?? null;
        if ($current === null) {
            return;
        }

        $this->children[$childInstanceId] = new WorkerChildInstance(
            $current->childInstanceId,
            $current->parentWorkerInstanceId,
            $current->pid,
            $state,
            $current->startedAt,
            $heartbeatAt,
            $current->queueMessageId,
            $current->messageId,
            $current->correlationId,
            $current->bindingId,
            $current->finishedAt,
            $current->failureMessage,
        );
    }

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void {
        $current = $this->children[$childInstanceId] ?? null;
        if ($current === null) {
            return;
        }

        $this->children[$childInstanceId] = new WorkerChildInstance(
            $current->childInstanceId,
            $current->parentWorkerInstanceId,
            $current->pid,
            $state,
            $current->startedAt,
            $finishedAt,
            $current->queueMessageId,
            $current->messageId,
            $current->correlationId,
            $current->bindingId,
            $finishedAt,
            $failureMessage,
        );
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        return $this->workers[$workerInstanceId] ?? null;
    }

    public function listWorkers(WorkerTarget $target): array
    {
        return \array_values(\array_filter(
            $this->workers,
            static fn (WorkerInstance $worker): bool => $target->matches($worker->identity),
        ));
    }

    public function listChildren(string $workerInstanceId): array
    {
        return \array_values(\array_filter(
            $this->children,
            static fn (WorkerChildInstance $child): bool => $child->parentWorkerInstanceId === $workerInstanceId,
        ));
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        return \array_values(\array_filter(
            $this->acknowledgements,
            static fn (WorkerControlAcknowledgement $acknowledgement): bool => $acknowledgement->commandId === $commandId,
        ));
    }
}
