<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Exception\MessageCancellationRequested;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunner;
use Wolfcharaa\MessageBus\Queue\PcntlAutoWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueWorkerInterface;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;
use Wolfcharaa\MessageBus\Tests\Support\WorkerControlMemoryRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgementState;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

#[RequiresPhpExtension('pcntl')]
final class PcntlAutoWorkerRunnerIntegrationTest extends TestCase
{
    public function testAutoRunnerAppliesStopCommandBeforeTakingNewMessages(): void
    {
        $control = new WorkerControlMemoryRuntime();
        $now = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $control->commands[] = new WorkerControlCommand(
            'command-stop',
            WorkerControlCommandType::Stop,
            WorkerTarget::all(),
            $now,
            expiresAt: $now->modify('+5 minutes'),
        );
        $parentConsumer = new PcntlAutoWorkerParentConsumer([
            $this->received('success', attempts: 0, maxAttempts: 3),
        ]);

        $runner = new PcntlAutoWorkerRunner(
            $parentConsumer,
            new PcntlAutoWorkerChildWorker([]),
            workerControlRuntime: $control->runtime(),
        );

        $result = $runner->run(
            new ConsumerOptions('postgres', 'default'),
            new PcntlAutoWorkerRunnerOptions(
                stopWhenEmpty: true,
                sleepWhenIdleMilliseconds: 1,
                workerInstanceId: 'instance-stop',
                controlPollIntervalMilliseconds: 1,
                heartbeatIntervalMilliseconds: 1,
            ),
        );

        self::assertSame(0, $result->handled);
        self::assertSame(WorkerLifecycleState::Stopped, $control->workers['instance-stop']->state);
        self::assertCount(1, $control->acknowledgements);
        self::assertSame(WorkerControlAcknowledgementState::Applied, $control->acknowledgements[0]->state);
    }

    public function testAutoRunnerRegistersWorkerAndChildLifecycle(): void
    {
        $logFile = \tempnam(\sys_get_temp_dir(), 'message-bus-pcntl-control-');
        self::assertIsString($logFile);
        $control = new WorkerControlMemoryRuntime();

        try {
            $runner = new PcntlAutoWorkerRunner(
                new PcntlAutoWorkerParentConsumer([
                    $this->received('success', attempts: 0, maxAttempts: 3),
                ]),
                new PcntlAutoWorkerChildWorker([]),
                childConsumerFactory: static fn (): PcntlAutoWorkerChildConsumer => new PcntlAutoWorkerChildConsumer($logFile),
                childWorkerFactory: static fn (): PcntlAutoWorkerChildWorker => new PcntlAutoWorkerChildWorker([]),
                workerControlRuntime: $control->runtime(),
            );

            $result = $runner->run(
                new ConsumerOptions('postgres', 'default'),
                new PcntlAutoWorkerRunnerOptions(
                    maxWorkers: 1,
                    maxMessages: 1,
                    stopWhenEmpty: true,
                    sleepWhenIdleMilliseconds: 1,
                    workerInstanceId: 'instance-lifecycle',
                    workerName: 'emails-worker',
                    workerGroup: 'emails',
                    controlPollIntervalMilliseconds: 1,
                    heartbeatIntervalMilliseconds: 1,
                ),
            );

            self::assertSame(1, $result->handled);
            self::assertSame(1, $result->succeeded);
            self::assertSame(WorkerLifecycleState::Stopped, $control->workers['instance-lifecycle']->state);
            self::assertSame('emails-worker', $control->workers['instance-lifecycle']->identity->workerName);
            self::assertCount(1, $control->children);
            self::assertSame(WorkerChildState::Succeeded, \array_values($control->children)[0]->state);
        } finally {
            @\unlink($logFile);
        }
    }

    public function testAutoRunnerForksChildrenAndReportsAllOutcomes(): void
    {
        $logFile = \tempnam(\sys_get_temp_dir(), 'message-bus-pcntl-');
        self::assertIsString($logFile);

        try {
            $parentConsumer = new PcntlAutoWorkerParentConsumer([
                $this->received('success', attempts: 0, maxAttempts: 3),
                $this->received('retry', attempts: 1, maxAttempts: 3),
                $this->received('reject', attempts: 3, maxAttempts: 3),
                $this->received('cancel', attempts: 0, maxAttempts: 3),
            ]);
            $runner = new PcntlAutoWorkerRunner(
                $parentConsumer,
                new PcntlAutoWorkerChildWorker([]),
                childConsumerFactory: static fn (): PcntlAutoWorkerChildConsumer => new PcntlAutoWorkerChildConsumer($logFile),
                childWorkerFactory: static fn (): PcntlAutoWorkerChildWorker => new PcntlAutoWorkerChildWorker([
                    'retry' => new RuntimeException('temporary failure'),
                    'reject' => new RuntimeException('attempts exhausted'),
                    'cancel' => new MessageCancellationRequested('cancel requested'),
                ]),
            );

            $result = $runner->run(
                new ConsumerOptions('postgres', 'default'),
                new PcntlAutoWorkerRunnerOptions(
                    maxWorkers: 2,
                    maxMessages: 4,
                    stopWhenEmpty: true,
                    sleepWhenIdleMilliseconds: 1,
                ),
            );

            $events = \file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            self::assertSame(4, $result->handled);
            self::assertSame(1, $result->succeeded);
            self::assertSame(1, $result->retried);
            self::assertSame(1, $result->rejected);
            self::assertSame(1, $result->cancelled);
            self::assertIsArray($events);
            \sort($events);
            self::assertSame([
                'ack:success',
                'cancel:cancel',
                'reject:reject',
                'retry:retry',
            ], $events);
        } finally {
            @\unlink($logFile);
        }
    }

    private function received(string $messageId, int $attempts, int $maxAttempts): ReceivedQueueMessage
    {
        $createdAt = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $envelope = new SerializedEnvelope(
            new SerializedMessage('pcntl_auto_worker.message', 'application/json', '{"id":"' . $messageId . '"}'),
            [],
            $messageId,
            null,
            'correlation-' . $messageId,
            'async',
            'pcntl.auto.' . $messageId,
            $createdAt,
        );

        return new ReceivedQueueMessage(
            'job-' . $messageId,
            new QueueMessage(
                'postgres',
                'default',
                $envelope,
                $messageId,
                'correlation-' . $messageId,
                'async',
                'pcntl.auto.' . $messageId,
                $createdAt,
                retryPolicySnapshot: new RetryPolicySnapshot($maxAttempts, 'fixed', ['delaySeconds' => 1]),
            ),
            $attempts,
        );
    }
}

final class PcntlAutoWorkerParentConsumer implements MessageConsumerInterface
{
    /** @var list<ReceivedQueueMessage> */
    private array $messages;

    /** @param list<ReceivedQueueMessage> $messages */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return \array_shift($this->messages);
    }

    public function ack(ReceivedQueueMessage $message): void
    {
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }
}

final class PcntlAutoWorkerChildConsumer implements MessageConsumerInterface
{
    public function __construct(private readonly string $logFile)
    {
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return null;
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        $this->write('ack', $message);
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->write('retry', $message);
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->write('reject', $message);
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->write('cancel', $message);
    }

    private function write(string $event, ReceivedQueueMessage $message): void
    {
        \file_put_contents($this->logFile, $event . ':' . $message->message->messageId . "\n", FILE_APPEND | LOCK_EX);
    }
}

final class PcntlAutoWorkerChildWorker implements QueueWorkerInterface
{
    /** @param array<string, \Throwable> $failuresByMessageId */
    public function __construct(private readonly array $failuresByMessageId)
    {
    }

    public function handle(SerializedEnvelope $envelope): mixed
    {
        $failure = $this->failuresByMessageId[$envelope->messageId] ?? null;

        if ($failure !== null) {
            throw $failure;
        }

        return 'ok';
    }
}
