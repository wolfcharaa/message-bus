<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerControlService;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerControlIdGeneratorInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlRequest;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class WorkerControlServiceTest extends TestCase
{
    public function testPauseWritesCommandAndDesiredState(): void
    {
        $repository = new WorkerControlServiceMemoryRepository();
        $service = new DefaultWorkerControlService($repository, $repository, new WorkerControlServiceDeterministicIds(), new WorkerControlServiceFrozenClock());

        $receipt = $service->pause(
            new WorkerTarget(workerGroup: 'emails'),
            new WorkerControlRequest(createdBy: 'root', source: 'ui', reason: 'maintenance'),
        );

        self::assertSame('command.pause.1', $receipt->commandId);
        self::assertSame(WorkerControlCommandType::Pause, $repository->commands[0]->type);
        self::assertSame(WorkerDesiredStateType::Paused, $repository->desiredStates[0]->type);
        self::assertSame('root', $repository->desiredStates[0]->createdBy);
    }

    public function testOneShotCommandGetsDefaultExpiry(): void
    {
        $repository = new WorkerControlServiceMemoryRepository();
        $service = new DefaultWorkerControlService($repository, $repository, new WorkerControlServiceDeterministicIds(), new WorkerControlServiceFrozenClock());

        $service->kill(new WorkerTarget(workerInstanceId: 'instance-1'));

        self::assertSame('2026-08-20T10:01:00+00:00', $repository->commands[0]->expiresAt?->format(DATE_ATOM));
    }
}

final class WorkerControlServiceMemoryRepository implements WorkerControlCommandRepositoryInterface, WorkerDesiredStateRepositoryInterface
{
    /** @var list<WorkerControlCommand> */
    public array $commands = [];

    /** @var list<WorkerDesiredState> */
    public array $desiredStates = [];

    public function append(WorkerControlCommand $command): \Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt
    {
        $this->commands[] = $command;

        return new \Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt(
            $command->commandId,
            $command->type,
            $command->target,
            $command->createdAt,
            $command->idempotencyKey,
        );
    }

    public function findById(string $commandId): ?WorkerControlCommand
    {
        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand
    {
        return null;
    }

    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        return [];
    }

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void
    {
    }

    public function apply(WorkerDesiredState $state): void
    {
        $this->desiredStates[] = $state;
    }

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState
    {
        return null;
    }

    public function list(WorkerTarget $target): array
    {
        return $this->desiredStates;
    }
}

final class WorkerControlServiceDeterministicIds implements WorkerControlIdGeneratorInterface
{
    private int $index = 0;

    public function nextCommandId(WorkerControlCommandType $type): string
    {
        ++$this->index;

        return 'command.' . $type->value . '.' . $this->index;
    }

    public function nextDesiredStateId(WorkerControlCommand $command): string
    {
        return 'desired.' . $command->commandId;
    }
}

final class WorkerControlServiceFrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-20T10:00:00+00:00');
    }
}
