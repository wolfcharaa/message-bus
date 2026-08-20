<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Exception\MessageCancellationRequested;
use Wolfcharaa\MessageBus\Exception\NonRetryableMessageHandlingFailed;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueWorkerInterface;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class QueueWorkerRunnerV5Test extends TestCase
{
    public function testRunnerAcksSuccessfulMessageAndStopsWhenEmpty(): void
    {
        $message = $this->received();
        $consumer = new QueueWorkerRunnerConsumer([$message]);
        $worker = new QueueWorkerRunnerWorker();

        $result = (new QueueWorkerRunner($consumer, $worker))->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true),
        );

        self::assertSame(1, $result->handled);
        self::assertSame(1, $result->succeeded);
        self::assertSame([$message], $consumer->acked);
        self::assertSame(1, $worker->calls);
    }

    public function testRunnerRetriesFailureBeforeMaxAttempts(): void
    {
        $message = $this->received(attempts: 1, maxAttempts: 3);
        $consumer = new QueueWorkerRunnerConsumer([$message]);
        $worker = new QueueWorkerRunnerWorker(new RuntimeException('temporary'));

        $result = (new QueueWorkerRunner($consumer, $worker))->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true),
        );

        self::assertSame(1, $result->handled);
        self::assertSame(1, $result->retried);
        self::assertSame([$message], $consumer->retried);
        self::assertSame([], $consumer->rejected);
    }

    public function testRunnerRejectsFailureWhenAttemptsAreExhausted(): void
    {
        $message = $this->received(attempts: 3, maxAttempts: 3);
        $consumer = new QueueWorkerRunnerConsumer([$message]);
        $worker = new QueueWorkerRunnerWorker(new RuntimeException('permanent by attempts'));

        $result = (new QueueWorkerRunner($consumer, $worker))->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true),
        );

        self::assertSame(1, $result->handled);
        self::assertSame(1, $result->rejected);
        self::assertSame([$message], $consumer->rejected);
        self::assertSame([], $consumer->retried);
    }

    public function testRunnerRejectsNonRetryableFailureImmediately(): void
    {
        $message = $this->received(attempts: 0, maxAttempts: 3);
        $consumer = new QueueWorkerRunnerConsumer([$message]);
        $worker = new QueueWorkerRunnerWorker(new NonRetryableMessageHandlingFailed('non retryable'));

        $result = (new QueueWorkerRunner($consumer, $worker))->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true),
        );

        self::assertSame(1, $result->rejected);
        self::assertSame([$message], $consumer->rejected);
        self::assertSame([], $consumer->retried);
    }

    public function testRunnerCancelsCancellationFailure(): void
    {
        $message = $this->received();
        $consumer = new QueueWorkerRunnerConsumer([$message]);
        $worker = new QueueWorkerRunnerWorker(new MessageCancellationRequested('cancel requested'));

        $result = (new QueueWorkerRunner($consumer, $worker))->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true),
        );

        self::assertSame(1, $result->cancelled);
        self::assertSame([$message], $consumer->cancelled);
        self::assertSame([], $consumer->acked);
        self::assertSame([], $consumer->retried);
        self::assertSame([], $consumer->rejected);
    }

    private function received(int $attempts = 0, int $maxAttempts = 3): ReceivedQueueMessage
    {
        $createdAt = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $envelope = new SerializedEnvelope(
            new SerializedMessage('queue_runner.message', 'application/json', '{"id":1}'),
            [],
            'message-1',
            null,
            'correlation-1',
            'async',
            'queue.runner.handle',
            $createdAt,
        );

        return new ReceivedQueueMessage(
            'job-1',
            new QueueMessage(
                'postgres',
                'default',
                $envelope,
                'message-1',
                'correlation-1',
                'async',
                'queue.runner.handle',
                $createdAt,
                retryPolicySnapshot: new RetryPolicySnapshot($maxAttempts, 'fixed', ['delaySeconds' => 1]),
            ),
            $attempts,
        );
    }
}

final class QueueWorkerRunnerConsumer implements MessageConsumerInterface
{
    /** @var list<ReceivedQueueMessage> */
    private array $messages;

    /** @var list<ReceivedQueueMessage> */
    public array $acked = [];

    /** @var list<ReceivedQueueMessage> */
    public array $retried = [];

    /** @var list<ReceivedQueueMessage> */
    public array $rejected = [];

    /** @var list<ReceivedQueueMessage> */
    public array $cancelled = [];

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
        $this->acked[] = $message;
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->retried[] = $message;
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->rejected[] = $message;
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->cancelled[] = $message;
    }
}

final class QueueWorkerRunnerWorker implements QueueWorkerInterface
{
    public int $calls = 0;

    public function __construct(private readonly ?\Throwable $failure = null)
    {
    }

    public function handle(SerializedEnvelope $envelope): mixed
    {
        ++$this->calls;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return 'ok';
    }
}
