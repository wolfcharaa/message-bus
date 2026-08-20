<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Wolfcharaa\MessageBus\Exception\MessageCancellationExceptionInterface;
use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Wolfcharaa\MessageBus\Runtime\WorkerControlRuntime;
use Wolfcharaa\MessageBus\Worker\QueueJobWorkerRuntimeControl;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgementState;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlScope;

final class PcntlAutoWorkerRunner
{
    private const EXIT_SUCCEEDED = 0;
    private const EXIT_RETRIED = 10;
    private const EXIT_REJECTED = 11;
    private const EXIT_CANCELLED = 12;

    public function __construct(
        private readonly MessageConsumerInterface $consumer,
        private readonly QueueWorkerInterface $worker,
        private readonly StopSignalProviderInterface $stopSignalProvider = new PcntlStopSignalProvider(),
        private readonly mixed $childConsumerFactory = null,
        private readonly mixed $childWorkerFactory = null,
        private readonly ?QueueJobControlInterface $queueControl = null,
        private readonly mixed $childWorkerRuntimeControlFactory = null,
        private readonly ?WorkerControlRuntime $workerControlRuntime = null,
    ) {
        if (!\extension_loaded('pcntl')) {
            throw new RuntimeException('Auto worker mode requires pcntl extension.');
        }
    }

    public function run(
        ConsumerOptions $consumerOptions,
        PcntlAutoWorkerRunnerOptions $options = new PcntlAutoWorkerRunnerOptions(),
    ): QueueWorkerRunResult {
        $startedAt = \time();
        $lastMessageAt = \time();
        $children = [];
        $handled = 0;
        $succeeded = 0;
        $retried = 0;
        $rejected = 0;
        $cancelled = 0;
        $exitCode = null;
        $identity = $this->identity($consumerOptions, $options);
        $lifecycle = WorkerLifecycleState::Starting;
        $paused = false;
        $draining = false;
        $stopping = false;
        $killing = false;
        $restarting = false;
        $killStartedAt = null;
        $cursor = new WorkerControlCursor();
        $lastControlPollAt = 0;
        $lastHeartbeatAt = 0;

        $this->registerWorker($identity, $lifecycle, WorkerActivityState::Idle, 0);

        while (!$this->shouldStop($startedAt, $lastMessageAt, $handled, $options)) {
            $this->reap($children, $succeeded, $retried, $rejected, $cancelled, $lifecycle);

            if ($this->shouldPoll($lastControlPollAt, $options->controlPollIntervalMilliseconds)) {
                $lastControlPollAt = $this->milliseconds();
                $control = $this->receiveControl($identity, $cursor);
                $cursor = $control['cursor'];

                foreach ($control['commands'] as $command) {
                    match ($command->type) {
                        WorkerControlCommandType::Pause => $paused = true,
                        WorkerControlCommandType::Resume => $paused = false,
                        WorkerControlCommandType::Drain => $draining = true,
                        WorkerControlCommandType::Stop => $stopping = true,
                        WorkerControlCommandType::Kill => $killing = true,
                        WorkerControlCommandType::Restart => $restarting = true,
                    };

                    $lifecycle = $this->lifecycleForCommand($command->type);
                    $this->acknowledgeCommand($identity, $command, WorkerControlAcknowledgementState::Applied);
                }

                if ($control['desiredPaused'] !== null && !$draining && !$stopping && !$killing && !$restarting) {
                    $paused = $control['desiredPaused'];
                    $lifecycle = $paused ? WorkerLifecycleState::Paused : WorkerLifecycleState::Running;
                }
            }

            if ($this->stopSignalProvider->shouldStop()) {
                $stopping = true;
                $lifecycle = WorkerLifecycleState::Stopping;
            }

            if ($killing && $killStartedAt === null) {
                $killStartedAt = \time();
                $this->signalChildren($children, SIGTERM);
            }

            if ($killing && $killStartedAt !== null && \time() - $killStartedAt >= $options->forceKillTimeoutSeconds) {
                $this->signalChildren($children, SIGKILL);
            }

            if ($this->shouldHeartbeat($lastHeartbeatAt, $options->heartbeatIntervalMilliseconds)) {
                $lastHeartbeatAt = $this->milliseconds();
                $this->heartbeatWorker(
                    $identity,
                    $lifecycle,
                    $children === [] ? WorkerActivityState::Idle : WorkerActivityState::Busy,
                    \count($children),
                );
                $this->heartbeatChildren($children);
            }

            if (($draining || $stopping || $killing || $restarting) && $children === []) {
                break;
            }

            if ($paused || $draining || $stopping || $killing || $restarting) {
                $this->idle($options);
                continue;
            }

            $lifecycle = WorkerLifecycleState::Running;

            while (\count($children) < \max(1, $options->maxWorkers)) {
                if ($options->maxMessages !== null && $handled >= $options->maxMessages) {
                    break;
                }

                $message = $this->consumer->next($consumerOptions);

                if ($message === null) {
                    if ($options->stopWhenEmpty && $children === []) {
                        break 2;
                    }

                    break;
                }

                $lastMessageAt = \time();
                ++$handled;
                $childInstanceId = $this->childInstanceId($identity, $message);
                $pid = \pcntl_fork();

                if ($pid === -1) {
                    $this->consumer->retry($message, new RuntimeException('Unable to fork worker process.'));
                    break;
                }

                if ($pid === 0) {
                    exit($this->handleChild($message, $childInstanceId));
                }

                $children[$pid] = [
                    'childInstanceId' => $childInstanceId,
                    'message' => $message,
                ];
                $this->registerChild($identity, $childInstanceId, $pid, $message);
            }

            $this->idle($options);
        }

        while ($children !== []) {
            if ($killing && $killStartedAt !== null && \time() - $killStartedAt >= $options->forceKillTimeoutSeconds) {
                $this->signalChildren($children, SIGKILL);
            }

            $this->reap($children, $succeeded, $retried, $rejected, $cancelled, $lifecycle, blocking: true);
        }

        if ($restarting) {
            $lifecycle = WorkerLifecycleState::Restarting;
            $exitCode = $options->restartExitCode;
        } else {
            $lifecycle = WorkerLifecycleState::Stopped;
        }

        $this->stopWorker($identity, $lifecycle);

        return new QueueWorkerRunResult($handled, $succeeded, $retried, $rejected, $cancelled, $exitCode);
    }

    private function handleChild(ReceivedQueueMessage $message, string $childInstanceId): int
    {
        $consumer = $this->childConsumerFactory === null ? $this->consumer : ($this->childConsumerFactory)();
        $worker = $this->childWorkerFactory === null ? $this->worker : ($this->childWorkerFactory)();

        if (!$consumer instanceof MessageConsumerInterface || !$worker instanceof QueueWorkerInterface) {
            return self::EXIT_REJECTED;
        }

        try {
            WorkerRuntimeControlScope::run(
                $this->runtimeControl($message, $childInstanceId),
                fn (): mixed => $worker->handle($message->message->envelope),
            );
            $consumer->ack($message);

            return self::EXIT_SUCCEEDED;
        } catch (\Throwable $e) {
            if ($e instanceof MessageCancellationExceptionInterface) {
                $consumer->cancel($message, $e);

                return self::EXIT_CANCELLED;
            }

            if ($e instanceof NonRetryableMessageExceptionInterface || !$this->shouldRetry($message)) {
                $consumer->reject($message, $e);

                return self::EXIT_REJECTED;
            }

            $consumer->retry($message, $e);

            return self::EXIT_RETRIED;
        }
    }

    /**
     * @param array<int, array{childInstanceId: string, message: ReceivedQueueMessage}> $children
     */
    private function reap(
        array &$children,
        int &$succeeded,
        int &$retried,
        int &$rejected,
        int &$cancelled,
        WorkerLifecycleState $workerLifecycle,
        bool $blocking = false,
    ): void {
        while ($children !== []) {
            $status = 0;
            $pid = \pcntl_waitpid(-1, $status, $blocking ? 0 : WNOHANG);

            if ($pid <= 0) {
                return;
            }

            $child = $children[$pid] ?? null;
            unset($children[$pid]);
            $exitCode = \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : self::EXIT_REJECTED;
            $childState = $this->childState($exitCode, $status, $workerLifecycle);

            match ($exitCode) {
                self::EXIT_SUCCEEDED => ++$succeeded,
                self::EXIT_RETRIED => ++$retried,
                self::EXIT_CANCELLED => ++$cancelled,
                default => ++$rejected,
            };

            if ($child !== null) {
                $this->finishChild($child['childInstanceId'], $childState);
            }
        }
    }

    private function shouldStop(
        int $startedAt,
        int $lastMessageAt,
        int $handled,
        PcntlAutoWorkerRunnerOptions $options,
    ): bool {
        if ($options->maxMessages !== null && $handled >= $options->maxMessages) {
            return true;
        }

        if ($options->maxRuntimeSeconds !== null && \time() - $startedAt >= $options->maxRuntimeSeconds) {
            return true;
        }

        if ($options->idleTimeoutSeconds !== null && \time() - $lastMessageAt >= $options->idleTimeoutSeconds) {
            return true;
        }

        return $options->memoryLimitBytes !== null && \memory_get_usage(true) >= $options->memoryLimitBytes;
    }

    private function shouldRetry(ReceivedQueueMessage $message): bool
    {
        return $message->attempts < $message->message->retryPolicySnapshot->maxAttempts;
    }

    private function identity(ConsumerOptions $consumerOptions, PcntlAutoWorkerRunnerOptions $options): WorkerIdentity
    {
        $host = $options->host ?? (\gethostname() ?: 'unknown');
        $pid = \getmypid() ?: 0;
        $workerName = $options->workerName ?? $consumerOptions->workerId;

        return new WorkerIdentity(
            workerName: $workerName,
            workerInstanceId: $options->workerInstanceId ?? $this->workerInstanceId($workerName, $host, $pid),
            workerGroup: $options->workerGroup,
            host: $host,
            pid: $pid,
            startedAt: $this->now(),
            mode: WorkerMode::Auto,
            transport: $consumerOptions->transport,
            queue: $consumerOptions->queue,
            flows: $consumerOptions->flows,
            bindingIds: $consumerOptions->bindingIds,
            bindingPatterns: $consumerOptions->bindingPatterns,
            workerId: $consumerOptions->workerId,
        );
    }

    private function workerInstanceId(string $workerName, string $host, int $pid): string
    {
        return \sprintf('worker.%s.%s.%d.%s', $workerName, $host, $pid, \bin2hex(\random_bytes(6)));
    }

    private function childInstanceId(WorkerIdentity $identity, ReceivedQueueMessage $message): string
    {
        return \sprintf(
            'worker_child.%s.%s.%s',
            $identity->workerInstanceId,
            $message->queueMessageId,
            \bin2hex(\random_bytes(4)),
        );
    }

    private function lifecycleForCommand(WorkerControlCommandType $type): WorkerLifecycleState
    {
        return match ($type) {
            WorkerControlCommandType::Pause => WorkerLifecycleState::Paused,
            WorkerControlCommandType::Resume => WorkerLifecycleState::Running,
            WorkerControlCommandType::Drain => WorkerLifecycleState::Draining,
            WorkerControlCommandType::Stop => WorkerLifecycleState::Stopping,
            WorkerControlCommandType::Kill => WorkerLifecycleState::Killing,
            WorkerControlCommandType::Restart => WorkerLifecycleState::Restarting,
        };
    }

    /**
     * @return array{commands: list<WorkerControlCommand>, cursor: WorkerControlCursor, desiredPaused: ?bool}
     */
    private function receiveControl(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        if ($this->workerControlRuntime === null) {
            return ['commands' => [], 'cursor' => $cursor, 'desiredPaused' => null];
        }

        $batch = $this->workerControlRuntime->inbox()->receive($identity, $cursor);

        return [
            'commands' => $batch->commands,
            'cursor' => $batch->cursor,
            'desiredPaused' => match ($batch->desiredState?->type) {
                WorkerDesiredStateType::Paused => true,
                WorkerDesiredStateType::Resumed => false,
                null => null,
            },
        ];
    }

    private function acknowledgeCommand(
        WorkerIdentity $identity,
        WorkerControlCommand $command,
        WorkerControlAcknowledgementState $state,
        ?string $message = null,
    ): void {
        $this->workerControlRuntime?->commandRepository()->acknowledge(new WorkerControlAcknowledgement(
            $command->commandId,
            $identity->workerInstanceId,
            $state,
            $this->now(),
            $message,
        ));
    }

    private function registerWorker(
        WorkerIdentity $identity,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        int $childrenCount,
    ): void {
        $this->workerControlRuntime?->workerRegistry()->registerWorker(new WorkerInstance(
            $identity,
            $state,
            $activity,
            $this->now(),
            $childrenCount,
        ));
    }

    private function heartbeatWorker(
        WorkerIdentity $identity,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        int $childrenCount,
    ): void {
        $this->workerControlRuntime?->workerRegistry()->heartbeatWorker(
            $identity->workerInstanceId,
            $state,
            $activity,
            $this->now(),
            $childrenCount,
        );
    }

    private function stopWorker(WorkerIdentity $identity, WorkerLifecycleState $state): void
    {
        $this->workerControlRuntime?->workerRegistry()->stopWorker(
            $identity->workerInstanceId,
            $state,
            $this->now(),
        );
    }

    private function registerChild(
        WorkerIdentity $identity,
        string $childInstanceId,
        int $pid,
        ReceivedQueueMessage $message,
    ): void {
        $this->workerControlRuntime?->workerRegistry()->registerChild(new WorkerChildInstance(
            $childInstanceId,
            $identity->workerInstanceId,
            $pid,
            WorkerChildState::Running,
            $this->now(),
            $this->now(),
            $message->queueMessageId,
            $message->message->messageId,
            $message->message->correlationId,
            $message->message->bindingId,
        ));
    }

    /**
     * @param array<int, array{childInstanceId: string, message: ReceivedQueueMessage}> $children
     */
    private function heartbeatChildren(array $children): void
    {
        foreach ($children as $child) {
            $this->workerControlRuntime?->workerRegistry()->heartbeatChild(
                $child['childInstanceId'],
                WorkerChildState::Running,
                $this->now(),
            );
        }
    }

    private function finishChild(string $childInstanceId, WorkerChildState $state): void
    {
        $this->workerControlRuntime?->workerRegistry()->finishChild(
            $childInstanceId,
            $state,
            $this->now(),
        );
    }

    private function runtimeControl(ReceivedQueueMessage $message, string $childInstanceId): ?WorkerRuntimeControlInterface
    {
        if ($this->childWorkerRuntimeControlFactory !== null) {
            $runtimeControl = ($this->childWorkerRuntimeControlFactory)($message, $childInstanceId);
            if ($runtimeControl !== null && !$runtimeControl instanceof WorkerRuntimeControlInterface) {
                throw new RuntimeException(\sprintf(
                    'Child worker runtime control factory must return `%s` or null, got `%s`.',
                    WorkerRuntimeControlInterface::class,
                    \is_object($runtimeControl) ? $runtimeControl::class : \get_debug_type($runtimeControl),
                ));
            }

            return $runtimeControl;
        }

        if ($this->queueControl === null && $this->workerControlRuntime === null) {
            return null;
        }

        return new QueueJobWorkerRuntimeControl(
            queueMessageId: $message->queueMessageId,
            queueControl: $this->queueControl,
            workerRegistry: $this->workerControlRuntime?->workerRegistry(),
            childInstanceId: $childInstanceId,
        );
    }

    private function childState(int $exitCode, int $status, WorkerLifecycleState $workerLifecycle): WorkerChildState
    {
        if (!\pcntl_wifexited($status) && $workerLifecycle === WorkerLifecycleState::Killing) {
            return WorkerChildState::Killed;
        }

        return match ($exitCode) {
            self::EXIT_SUCCEEDED => WorkerChildState::Succeeded,
            self::EXIT_RETRIED => WorkerChildState::Retrying,
            self::EXIT_CANCELLED => WorkerChildState::Cancelled,
            self::EXIT_REJECTED => WorkerChildState::Rejected,
            default => WorkerChildState::Failed,
        };
    }

    /**
     * @param array<int, array{childInstanceId: string, message: ReceivedQueueMessage}> $children
     */
    private function signalChildren(array $children, int $signal): void
    {
        if (!\function_exists('posix_kill')) {
            return;
        }

        foreach (\array_keys($children) as $pid) {
            @\posix_kill($pid, $signal);
        }
    }

    private function idle(PcntlAutoWorkerRunnerOptions $options): void
    {
        $milliseconds = \max(0, $options->sleepWhenIdleMilliseconds);

        if ($this->workerControlRuntime?->notifier() !== null) {
            $this->workerControlRuntime->notifier()->waitForControlSignal($milliseconds);

            return;
        }

        \usleep($milliseconds * 1000);
    }

    private function shouldPoll(int $lastPollAt, int $intervalMilliseconds): bool
    {
        return $this->milliseconds() - $lastPollAt >= \max(1, $intervalMilliseconds);
    }

    private function shouldHeartbeat(int $lastHeartbeatAt, int $intervalMilliseconds): bool
    {
        return $this->milliseconds() - $lastHeartbeatAt >= \max(1, $intervalMilliseconds);
    }

    private function milliseconds(): int
    {
        return (int) \floor(\microtime(true) * 1000);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
