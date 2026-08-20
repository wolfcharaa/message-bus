<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Message\IncrementalMessageIdGenerator;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\PublishFailed;
use Wolfcharaa\MessageBus\PublishedExecutionMode;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class PublishResultV5Test extends TestCase
{
    public function testPublishReturnsQueuedExecutionsWithQueueMetadata(): void
    {
        $provider = new PublishResultRecordingQueueProvider();
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);

        $result = $bus->publish(new PublishResultOrderCreated(1001));

        self::assertFalse($result->hasFailures());
        self::assertCount(2, $result->executions());
        self::assertSame(PublishedExecutionMode::Queued, $result->executions()[0]->mode);
        self::assertSame(QueueJobState::Pending, $result->executions()[0]->status);
        self::assertSame('1', $result->executions()[0]->messageId);
        self::assertSame('1', $result->executions()[0]->correlationId);
        self::assertSame('orders', $result->executions()[0]->flow);
        self::assertSame('order.receipt', $result->executions()[0]->bindingId);
        self::assertSame('job-1', $result->executions()[0]->queueMessageId);
        self::assertSame('postgres', $result->executions()[0]->transport);
        self::assertSame('critical', $result->executions()[0]->queue);
        self::assertCount(2, $provider->messages);
        self::assertSame('order.audit', $provider->messages[1]->bindingId);
    }

    public function testPublishManyMergesDefaultAndPerItemOptions(): void
    {
        $provider = new PublishResultRecordingQueueProvider();
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);

        $result = $bus->publishMany([
            new PublishResultOrderCreated(1001),
            new MessageBatchItem(
                new PublishResultOrderCreated(1002),
                new PublishOptions(
                    messageId: 'custom-message-id',
                    headers: Headers::empty()->with('tenant', 'tenant-b'),
                ),
            ),
        ], new PublishOptions(headers: Headers::empty()->with('tenant', 'tenant-a')->with('source', 'batch-test')));

        self::assertCount(4, $result->executions());
        self::assertSame('1', $provider->messages[0]->messageId);
        self::assertSame('custom-message-id', $provider->messages[2]->messageId);
        self::assertSame('tenant-b', $provider->messages[2]->envelope->headers['tenant']);
        self::assertSame('batch-test', $provider->messages[2]->envelope->headers['source']);
    }

    public function testPublishFailedContainsPartialQueuedResultAndFailure(): void
    {
        $provider = new PublishResultRecordingQueueProvider(['order.audit']);
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);

        try {
            $bus->publish(new PublishResultOrderCreated(1001));
            self::fail('PublishFailed was not thrown.');
        } catch (PublishFailed $e) {
            $result = $e->result();
        }

        self::assertTrue($result->hasFailures());
        self::assertCount(1, $result->executions());
        self::assertCount(1, $result->failures());
        self::assertSame('order.receipt', $result->executions()[0]->bindingId);
        self::assertSame('order.audit', $result->failures()[0]->bindingId);
        self::assertSame(RuntimeException::class, $result->failures()[0]->errorClass);
        self::assertSame('enqueue failed for order.audit', $result->failures()[0]->errorMessage);
    }

    public function testForceSyncPublishesAsyncBindingsWithoutQueueAndReturnsSyncExecutions(): void
    {
        PublishResultAsyncRecorder::$calls = [];
        $provider = new PublishResultRecordingQueueProvider();
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);
        $previous = \getenv('MESSAGE_BUS_FORCE_SYNC');
        \putenv('MESSAGE_BUS_FORCE_SYNC=1');

        try {
            $result = $bus->publish(new PublishResultOrderCreated(1001));
        } finally {
            if ($previous === false) {
                \putenv('MESSAGE_BUS_FORCE_SYNC');
            } else {
                \putenv('MESSAGE_BUS_FORCE_SYNC=' . $previous);
            }
        }

        self::assertSame([], $provider->messages);
        self::assertSame(['receipt:1001', 'audit:1001'], PublishResultAsyncRecorder::$calls);
        self::assertCount(2, $result->executions());
        self::assertSame(PublishedExecutionMode::Sync, $result->executions()[0]->mode);
        self::assertSame(QueueJobState::Succeeded, $result->executions()[0]->status);
    }

    private function bus(CompiledMessageRegistry $registry, QueueProviderInterface $provider): MessageBus
    {
        return new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new PublishResultFrozenClock(),
        );
    }

    private function registry(): CompiledMessageRegistry
    {
        $flows = new FlowRegistry(
            FlowDefinition::async('orders')->transport('postgres', 'critical'),
        );
        $definition = (new MessageRegistryCompiler())->compile(
            new ClassListProvider([
                PublishResultOrderCreated::class,
                PublishResultSendReceipt::class,
                PublishResultWriteAudit::class,
            ]),
            $flows,
            '5.0.0',
            'publish-result-v5-test',
        );

        return new CompiledMessageRegistry($definition);
    }
}

final class PublishResultFrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-20T10:00:00+00:00');
    }
}

final class PublishResultRecordingQueueProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    /** @param list<string> $failedBindingIds */
    public function __construct(private readonly array $failedBindingIds = [])
    {
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        if (\in_array($message->bindingId, $this->failedBindingIds, true)) {
            throw new RuntimeException('enqueue failed for ' . $message->bindingId);
        }

        $index = \count($this->messages);

        return new QueueEnqueueResult(
            'job-' . $index,
            backendId: 'postgres-' . $index,
            status: QueueJobState::Pending,
            createdAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            metadata: ['index' => $index],
        );
    }
}

#[MessageAlias('publish_result.order_created')]
final class PublishResultOrderCreated
{
    public function __construct(public readonly int $orderId)
    {
    }
}

final class PublishResultAsyncRecorder
{
    /** @var list<string> */
    public static array $calls = [];
}

#[EventSubscriber(
    message: PublishResultOrderCreated::class,
    flow: 'orders',
    bindingId: 'order.receipt',
)]
final class PublishResultSendReceipt
{
    public function __invoke(PublishResultOrderCreated $message, \Wolfcharaa\MessageBus\Context\MessageContextInterface $context): void
    {
        PublishResultAsyncRecorder::$calls[] = 'receipt:' . $message->orderId;
    }
}

#[EventSubscriber(
    message: PublishResultOrderCreated::class,
    flow: 'orders',
    bindingId: 'order.audit',
)]
final class PublishResultWriteAudit
{
    public function __invoke(PublishResultOrderCreated $message, \Wolfcharaa\MessageBus\Context\MessageContextInterface $context): void
    {
        PublishResultAsyncRecorder::$calls[] = 'audit:' . $message->orderId;
    }
}
