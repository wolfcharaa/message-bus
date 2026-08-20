<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use Wolfcharaa\MessageBus\Exception\MessageCancellationExceptionInterface;
use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class QueueWorkerRunner
{
    public function __construct(
        private readonly MessageConsumerInterface $consumer,
        private readonly QueueWorkerInterface $worker,
        private readonly StopSignalProviderInterface $stopSignalProvider = new NullStopSignalProvider(),
    ) {
    }

    public function run(
        ConsumerOptions $consumerOptions,
        QueueWorkerRunnerOptions $runnerOptions = new QueueWorkerRunnerOptions(),
    ): QueueWorkerRunResult {
        $startedAt = \time();
        $lastMessageAt = \time();
        $handled = 0;
        $succeeded = 0;
        $retried = 0;
        $rejected = 0;
        $cancelled = 0;

        while (!$this->shouldStop($startedAt, $lastMessageAt, $handled, $runnerOptions)) {
            $message = $this->consumer->next($consumerOptions);

            if ($message === null) {
                if ($runnerOptions->stopWhenEmpty) {
                    break;
                }

                \usleep(\max(0, $runnerOptions->sleepWhenIdleMilliseconds) * 1000);
                continue;
            }

            $lastMessageAt = \time();
            ++$handled;

            try {
                $this->worker->handle($message->message->envelope);
                $this->consumer->ack($message);
                ++$succeeded;
            } catch (\Throwable $e) {
                if ($e instanceof MessageCancellationExceptionInterface) {
                    $this->consumer->cancel($message, $e);
                    ++$cancelled;
                    continue;
                }

                if ($e instanceof NonRetryableMessageExceptionInterface || !$this->shouldRetry($message)) {
                    $this->consumer->reject($message, $e);
                    ++$rejected;
                    continue;
                }

                $this->consumer->retry($message, $e);
                ++$retried;
            }
        }

        return new QueueWorkerRunResult($handled, $succeeded, $retried, $rejected, $cancelled);
    }

    private function shouldStop(
        int $startedAt,
        int $lastMessageAt,
        int $handled,
        QueueWorkerRunnerOptions $options,
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
