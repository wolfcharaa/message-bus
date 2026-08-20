<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use RuntimeException;
use Wolfcharaa\MessageBus\Exception\MessageCancellationExceptionInterface;
use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

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

        while (!$this->shouldStop($startedAt, $lastMessageAt, $handled, $options)) {
            $this->reap($children, $succeeded, $retried, $rejected, $cancelled);

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
                $pid = \pcntl_fork();

                if ($pid === -1) {
                    $this->consumer->retry($message, new RuntimeException('Unable to fork worker process.'));
                    break;
                }

                if ($pid === 0) {
                    exit($this->handleChild($message));
                }

                $children[$pid] = true;
            }

            \usleep(\max(0, $options->sleepWhenIdleMilliseconds) * 1000);
        }

        while ($children !== []) {
            $this->reap($children, $succeeded, $retried, $rejected, $cancelled, blocking: true);
        }

        return new QueueWorkerRunResult($handled, $succeeded, $retried, $rejected, $cancelled);
    }

    private function handleChild(ReceivedQueueMessage $message): int
    {
        $consumer = $this->childConsumerFactory === null ? $this->consumer : ($this->childConsumerFactory)();
        $worker = $this->childWorkerFactory === null ? $this->worker : ($this->childWorkerFactory)();

        if (!$consumer instanceof MessageConsumerInterface || !$worker instanceof QueueWorkerInterface) {
            return self::EXIT_REJECTED;
        }

        try {
            $worker->handle($message->message->envelope);
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
     * @param array<int, true> $children
     */
    private function reap(
        array &$children,
        int &$succeeded,
        int &$retried,
        int &$rejected,
        int &$cancelled,
        bool $blocking = false,
    ): void {
        while ($children !== []) {
            $status = 0;
            $pid = \pcntl_waitpid(-1, $status, $blocking ? 0 : WNOHANG);

            if ($pid <= 0) {
                return;
            }

            unset($children[$pid]);
            $exitCode = \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : self::EXIT_REJECTED;

            match ($exitCode) {
                self::EXIT_SUCCEEDED => ++$succeeded,
                self::EXIT_RETRIED => ++$retried,
                self::EXIT_CANCELLED => ++$cancelled,
                default => ++$rejected,
            };
        }
    }

    private function shouldStop(
        int $startedAt,
        int $lastMessageAt,
        int $handled,
        PcntlAutoWorkerRunnerOptions $options,
    ): bool {
        if ($this->stopSignalProvider->shouldStop()) {
            return true;
        }

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
}
