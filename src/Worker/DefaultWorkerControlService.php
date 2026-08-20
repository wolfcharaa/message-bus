<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class DefaultWorkerControlService implements WorkerControlServiceInterface
{
    public const DEFAULT_GRACEFUL_COMMAND_TTL_SECONDS = 300;
    public const DEFAULT_KILL_COMMAND_TTL_SECONDS = 60;

    public function __construct(
        private readonly WorkerControlCommandRepositoryInterface $commands,
        private readonly WorkerDesiredStateRepositoryInterface $desiredStates,
        private readonly WorkerControlIdGeneratorInterface $ids = new RandomWorkerControlIdGenerator(),
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function send(WorkerControlCommand $command): WorkerControlCommandReceipt
    {
        $receipt = $this->commands->append($command);

        if (!$receipt->duplicate && $command->type === WorkerControlCommandType::Pause) {
            $this->desiredStates->apply($this->desiredState($command, WorkerDesiredStateType::Paused));
        }

        if (!$receipt->duplicate && $command->type === WorkerControlCommandType::Resume) {
            $this->desiredStates->apply($this->desiredState($command, WorkerDesiredStateType::Resumed));
        }

        return $receipt;
    }

    public function pause(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Pause, $target, $request));
    }

    public function resume(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Resume, $target, $request));
    }

    public function drain(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Drain, $target, $request));
    }

    public function stop(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Stop, $target, $request));
    }

    public function kill(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Kill, $target, $request));
    }

    public function restart(WorkerTarget $target, ?WorkerControlRequest $request = null): WorkerControlCommandReceipt
    {
        return $this->send($this->command(WorkerControlCommandType::Restart, $target, $request));
    }

    private function command(
        WorkerControlCommandType $type,
        WorkerTarget $target,
        ?WorkerControlRequest $request,
    ): WorkerControlCommand {
        $request ??= new WorkerControlRequest();
        $now = $this->now();
        $expiresAt = $request->expiresAt;

        if ($type->isOneShot() && $expiresAt === null) {
            $expiresAt = $now->modify('+' . $this->defaultTtlSeconds($type) . ' seconds');
        }

        return new WorkerControlCommand(
            $this->ids->nextCommandId($type),
            $type,
            $target,
            $now,
            $request->createdBy,
            $request->source,
            $request->reason,
            $request->requestId,
            $request->correlationId,
            $expiresAt,
            $request->idempotencyKey,
            $request->override,
        );
    }

    private function desiredState(WorkerControlCommand $command, WorkerDesiredStateType $type): WorkerDesiredState
    {
        return new WorkerDesiredState(
            $this->ids->nextDesiredStateId($command),
            $type,
            $command->target,
            $command->createdAt,
            $command->createdBy,
            $command->source,
            $command->reason,
            $command->requestId,
            $command->correlationId,
            $command->override,
        );
    }

    private function defaultTtlSeconds(WorkerControlCommandType $type): int
    {
        return $type === WorkerControlCommandType::Kill
            ? self::DEFAULT_KILL_COMMAND_TTL_SECONDS
            : self::DEFAULT_GRACEFUL_COMMAND_TTL_SECONDS;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable('now');
    }
}
