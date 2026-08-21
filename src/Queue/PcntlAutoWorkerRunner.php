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
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputVerbosity;
use Wolfcharaa\MessageBus\Worker\WorkerCliOutputWriterInterface;
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
        private readonly ?WorkerCliOutputWriterInterface $output = null,
        private readonly mixed $beforeFork = null,
        private readonly mixed $afterForkInParent = null,
        private readonly mixed $afterForkInChild = null,
    ) {
        if (!\extension_loaded('pcntl')) {
            throw new RuntimeException('Auto worker mode requires pcntl extension.');
        }

        if (!\extension_loaded('posix')) {
            throw new RuntimeException('Auto worker mode requires posix extension.');
        }

        foreach ([
            'beforeFork' => $this->beforeFork,
            'afterForkInParent' => $this->afterForkInParent,
            'afterForkInChild' => $this->afterForkInChild,
        ] as $name => $hook) {
            if ($hook !== null && !\is_callable($hook)) {
                throw new RuntimeException(\sprintf('PcntlAutoWorkerRunner %s hook must be callable or null.', $name));
            }
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
        $stopSignalReported = false;
        $consecutiveHeartbeatFailures = 0;
        $sigkillSent = false;

        $this->output('info', 'worker.started', 'Auto worker started.', [
            'mode' => 'auto',
            'worker_instance_id' => $identity->workerInstanceId,
            'worker_name' => $identity->workerName,
            'worker_group' => $identity->workerGroup,
            'transport' => $consumerOptions->transport,
            'queue' => $consumerOptions->queue,
            'max_workers' => $options->maxWorkers,
            'control_poll_interval_ms' => $options->controlPollIntervalMilliseconds,
            'heartbeat_interval_ms' => $options->heartbeatIntervalMilliseconds,
        ]);
        $this->registerWorker($identity, $lifecycle, WorkerActivityState::Idle, 0);

        while (!$this->shouldStop($startedAt, $lastMessageAt, $handled, $options)) {
            $this->reap($children, $succeeded, $retried, $rejected, $cancelled, $lifecycle);

            if ($this->shouldPoll($lastControlPollAt, $options->controlPollIntervalMilliseconds)) {
                $lastControlPollAt = $this->milliseconds();
                try {
                    $control = $this->receiveControl($identity, $cursor);
                    $cursor = $control['cursor'];
                } catch (\Throwable $error) {
                    $this->output('error', 'worker.control_poll_failed', 'Worker control polling failed after retry policy.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'exception' => $error,
                    ]);
                    $this->storageFailureBackoff($options);
                    continue;
                }

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
                    $this->output('info', 'worker.control_command_applied', 'Worker control command applied.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'command_id' => $command->commandId,
                        'command_type' => $command->type,
                        'state' => $lifecycle,
                    ]);
                }

                if ($control['desiredPaused'] !== null && !$draining && !$stopping && !$killing && !$restarting) {
                    $paused = $control['desiredPaused'];
                    $lifecycle = $paused ? WorkerLifecycleState::Paused : WorkerLifecycleState::Running;
                }
            }

            if ($this->stopSignalProvider->shouldStop()) {
                $stopping = true;
                $lifecycle = WorkerLifecycleState::Stopping;
                if (!$stopSignalReported) {
                    $stopSignalReported = true;
                    $this->output('info', 'worker.stopping', 'Stop signal received, worker is stopping.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                    ]);
                }
            }

            if ($killing && $killStartedAt === null) {
                $killStartedAt = \time();
                $this->signalChildren($children, SIGTERM);
                $this->output('error', 'worker.children_sigterm', 'SIGTERM sent to child workers.', [
                    'worker_instance_id' => $identity->workerInstanceId,
                    'children' => \count($children),
                ]);
            }

            if ($killing && !$sigkillSent && $killStartedAt !== null && \time() - $killStartedAt >= $options->forceKillTimeoutSeconds) {
                $sigkillSent = true;
                $this->signalChildren($children, SIGKILL);
                $this->output('error', 'worker.children_sigkill', 'SIGKILL sent to child workers after force timeout.', [
                    'worker_instance_id' => $identity->workerInstanceId,
                    'children' => \count($children),
                ]);
            }

            if ($this->shouldHeartbeat($lastHeartbeatAt, $options->heartbeatIntervalMilliseconds)) {
                $lastHeartbeatAt = $this->milliseconds();
                try {
                    $this->heartbeatWorker(
                        $identity,
                        $lifecycle,
                        $children === [] ? WorkerActivityState::Idle : WorkerActivityState::Busy,
                        \count($children),
                    );
                    $this->heartbeatChildren($children);
                    $consecutiveHeartbeatFailures = 0;
                } catch (\Throwable $error) {
                    ++$consecutiveHeartbeatFailures;
                    $this->output('error', 'worker.heartbeat_failed', 'Worker heartbeat failed after retry policy.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'consecutive_failures' => $consecutiveHeartbeatFailures,
                        'max_consecutive_failures' => $options->maxConsecutiveHeartbeatFailures,
                        'exception' => $error,
                    ]);

                    if ($consecutiveHeartbeatFailures >= $options->maxConsecutiveHeartbeatFailures) {
                        throw $error;
                    }

                    $this->storageFailureBackoff($options);
                    continue;
                }

                $this->output('info', 'worker.heartbeat', 'Auto worker heartbeat.', [
                    'worker_instance_id' => $identity->workerInstanceId,
                    'state' => $lifecycle,
                    'activity' => $children === [] ? WorkerActivityState::Idle : WorkerActivityState::Busy,
                    'children' => \count($children),
                    'handled' => $handled,
                    'succeeded' => $succeeded,
                    'retried' => $retried,
                    'rejected' => $rejected,
                    'cancelled' => $cancelled,
                ]);
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

                try {
                    $message = $this->consumer->next($consumerOptions);
                } catch (\Throwable $error) {
                    $this->output('error', 'worker.queue_poll_failed', 'Queue polling failed after retry policy.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'transport' => $consumerOptions->transport,
                        'queue' => $consumerOptions->queue,
                        'exception' => $error,
                    ]);
                    $this->storageFailureBackoff($options);
                    break;
                }

                if ($message === null) {
                    if ($options->stopWhenEmpty && $children === []) {
                        break 2;
                    }

                    break;
                }

                $lastMessageAt = \time();
                ++$handled;
                $childInstanceId = $this->childInstanceId($identity, $message);
                $this->callForkHook($this->beforeFork, $message, $childInstanceId, null);
                $pid = \pcntl_fork();

                if ($pid === -1) {
                    $this->output('error', 'worker.child_fork_failed', 'Unable to fork worker child process.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'queue_message_id' => $message->queueMessageId,
                    ]);
                    $this->consumer->retry($message, new RuntimeException('Unable to fork worker process.'));
                    break;
                }

                if ($pid === 0) {
                    $this->callForkHook($this->afterForkInChild, $message, $childInstanceId, null);
                    exit($this->handleChild($message, $childInstanceId));
                }

                $this->callForkHook($this->afterForkInParent, $message, $childInstanceId, $pid);
                $children[$pid] = [
                    'childInstanceId' => $childInstanceId,
                    'message' => $message,
                ];
                try {
                    $this->registerChild($identity, $childInstanceId, $pid, $message);
                } catch (\Throwable $error) {
                    $this->output('error', 'worker.child_register_failed', 'Worker child registration failed after retry policy.', [
                        'worker_instance_id' => $identity->workerInstanceId,
                        'child_instance_id' => $childInstanceId,
                        'pid' => $pid,
                        'queue_message_id' => $message->queueMessageId,
                        'exception' => $error,
                    ]);
                }
                $this->output('debug', 'worker.child_started', 'Worker child process started.', [
                    'worker_instance_id' => $identity->workerInstanceId,
                    'child_instance_id' => $childInstanceId,
                    'pid' => $pid,
                    'queue_message_id' => $message->queueMessageId,
                    'message_id' => $message->message->messageId,
                    'binding_id' => $message->message->bindingId,
                ], WorkerCliOutputVerbosity::Debug);
            }

            $this->idle($options);
        }

        while ($children !== []) {
            if ($killing && !$sigkillSent && $killStartedAt !== null && \time() - $killStartedAt >= $options->forceKillTimeoutSeconds) {
                $sigkillSent = true;
                $this->signalChildren($children, SIGKILL);
            }

            $this->reap($children, $succeeded, $retried, $rejected, $cancelled, $lifecycle, blocking: true);
        }

        if ($restarting) {
            $lifecycle = WorkerLifecycleState::Restarting;
            $exitCode = $options->restartExitCode;
            $this->output('info', 'worker.restart_requested', 'Worker restart requested, returning restart exit code.', [
                'worker_instance_id' => $identity->workerInstanceId,
                'exit_code' => $exitCode,
            ]);
        } else {
            $lifecycle = WorkerLifecycleState::Stopped;
        }

        try {
            $this->stopWorker($identity, $lifecycle);
        } catch (\Throwable $error) {
            $this->output('error', 'worker.stop_register_failed', 'Worker stop registration failed after retry policy.', [
                'worker_instance_id' => $identity->workerInstanceId,
                'state' => $lifecycle,
                'exception' => $error,
            ]);
        }
        $this->output('info', 'worker.stopped', 'Auto worker stopped.', [
            'worker_instance_id' => $identity->workerInstanceId,
            'state' => $lifecycle,
            'handled' => $handled,
            'succeeded' => $succeeded,
            'retried' => $retried,
            'rejected' => $rejected,
            'cancelled' => $cancelled,
            'exit_code' => $exitCode ?? 0,
        ]);

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
                try {
                    $this->finishChild($child['childInstanceId'], $childState);
                    $this->output('debug', 'worker.child_finished', 'Worker child process finished.', [
                        'child_instance_id' => $child['childInstanceId'],
                        'pid' => $pid,
                        'state' => $childState,
                        'exit_code' => $exitCode,
                        'queue_message_id' => $child['message']->queueMessageId,
                        'message_id' => $child['message']->message->messageId,
                    ], WorkerCliOutputVerbosity::Debug);
                } catch (\Throwable $error) {
                    $this->output('error', 'worker.child_finish_failed', 'Worker child finalization failed after retry policy.', [
                        'child_instance_id' => $child['childInstanceId'],
                        'pid' => $pid,
                        'state' => $childState,
                        'exit_code' => $exitCode,
                        'queue_message_id' => $child['message']->queueMessageId,
                        'message_id' => $child['message']->message->messageId,
                        'exception' => $error,
                    ]);
                }
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

    private function storageFailureBackoff(PcntlAutoWorkerRunnerOptions $options): void
    {
        $milliseconds = \max(1, $options->storageFailureBackoffMilliseconds);

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

    /**
     * @param array<string, mixed> $context
     */
    private function output(
        string $level,
        string $event,
        string $message,
        array $context = [],
        WorkerCliOutputVerbosity $verbosity = WorkerCliOutputVerbosity::Normal,
    ): void {
        $this->output?->write($level, $event, $message, $context, $verbosity);
    }

    private function callForkHook(
        mixed $hook,
        ReceivedQueueMessage $message,
        string $childInstanceId,
        ?int $pid,
    ): void {
        if ($hook === null) {
            return;
        }

        $hook($message, $childInstanceId, $pid);
    }
}
