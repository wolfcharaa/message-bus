<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Context\CancellableMessageContextInterface;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Context\HeartbeatAwareMessageContextInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\MessageBusQueueWorker;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunnerOptions;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;
use Wolfcharaa\MessageBus\Worker\QueueJobWorkerRuntimeControl;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlScope;

final class WorkerRuntimeContextTest extends TestCase
{
    public function testDefaultContextExposesWorkerRuntimeControl(): void
    {
        WorkerRuntimeContextAction::$heartbeatAware = false;
        WorkerRuntimeContextAction::$cancellable = false;
        WorkerRuntimeContextAction::$cancelRequested = false;

        $runtimeControl = new QueueJobWorkerRuntimeControl('job-1', new WorkerRuntimeQueueControl(cancellationRequested: true));
        $bus = $this->bus([
            WorkerRuntimeContextMessage::class,
            WorkerRuntimeContextAction::class,
        ]);

        WorkerRuntimeControlScope::run(
            $runtimeControl,
            static fn (): mixed => $bus->dispatch(new WorkerRuntimeContextMessage()),
        );

        self::assertTrue(WorkerRuntimeContextAction::$heartbeatAware);
        self::assertTrue(WorkerRuntimeContextAction::$cancellable);
        self::assertTrue(WorkerRuntimeContextAction::$cancelRequested);
    }

    public function testQueueRunnerScopesCancellationAndHeartbeatForCurrentJob(): void
    {
        WorkerRuntimeQueueAction::$handled = false;

        $queueControl = new WorkerRuntimeQueueControl(cancellationRequested: true);
        $bus = $this->bus([
            WorkerRuntimeQueueMessage::class,
            WorkerRuntimeQueueAction::class,
        ]);
        $serializer = new \Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer(
            new JsonMessageSerializer($this->registry([
                WorkerRuntimeQueueMessage::class,
                WorkerRuntimeQueueAction::class,
            ])),
        );
        $createdAt = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $envelope = new \Wolfcharaa\MessageBus\Envelope\SerializedEnvelope(
            new SerializedMessage('worker.runtime.queue', 'application/json', '{}'),
            [],
            'message-queue-1',
            null,
            'correlation-queue-1',
            'default',
            WorkerRuntimeQueueAction::class,
            $createdAt,
        );
        $consumer = new WorkerRuntimeConsumer(new ReceivedQueueMessage(
            'queue-job-1',
            new QueueMessage(
                'postgres',
                'default',
                $envelope,
                $envelope->messageId,
                $envelope->correlationId,
                'default',
                WorkerRuntimeQueueAction::class,
                $createdAt,
                retryPolicySnapshot: new RetryPolicySnapshot(1),
            ),
        ));
        $runner = new QueueWorkerRunner(
            $consumer,
            new MessageBusQueueWorker($bus, $serializer),
            queueControl: $queueControl,
        );

        $result = $runner->run(
            new ConsumerOptions('postgres', 'default'),
            new QueueWorkerRunnerOptions(maxMessages: 1, stopWhenEmpty: true, sleepWhenIdleMilliseconds: 1),
        );

        self::assertTrue(WorkerRuntimeQueueAction::$handled);
        self::assertSame(['queue-job-1'], $queueControl->heartbeats);
        self::assertSame(1, $result->handled);
        self::assertSame(0, $result->succeeded);
        self::assertSame(1, $result->cancelled);
        self::assertSame(['queue-job-1'], $consumer->cancelled);
    }

    /**
     * @param list<class-string> $classes
     */
    private function bus(array $classes): MessageBus
    {
        $registry = $this->registry($classes);

        return new MessageBus(
            registry: $registry,
            flows: $registry->definition()->flows,
            container: new TestContainer([
                DefaultMessageContextFactory::class => new DefaultMessageContextFactory(),
                SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
                WorkerRuntimeContextAction::class => new WorkerRuntimeContextAction(),
                WorkerRuntimeQueueAction::class => new WorkerRuntimeQueueAction(),
            ]),
        );
    }

    /**
     * @param list<class-string> $classes
     */
    private function registry(array $classes): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry((new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            new FlowRegistry(),
            '5.0.0',
            'worker-runtime-context-test',
        ));
    }
}

final class WorkerRuntimeContextMessage
{
}

#[CommandHandler(message: WorkerRuntimeContextMessage::class)]
final class WorkerRuntimeContextAction
{
    public static bool $heartbeatAware = false;
    public static bool $cancellable = false;
    public static bool $cancelRequested = false;

    public function __invoke(WorkerRuntimeContextMessage $message, MessageContextInterface $context): string
    {
        self::$heartbeatAware = $context instanceof HeartbeatAwareMessageContextInterface;
        self::$cancellable = $context instanceof CancellableMessageContextInterface;

        if ($context instanceof HeartbeatAwareMessageContextInterface) {
            $context->heartbeat();
        }

        if ($context instanceof CancellableMessageContextInterface) {
            self::$cancelRequested = $context->isCancellationRequested();
        }

        return 'ok';
    }
}

#[MessageAlias('worker.runtime.queue')]
final class WorkerRuntimeQueueMessage
{
}

#[CommandHandler(message: WorkerRuntimeQueueMessage::class, bindingId: WorkerRuntimeQueueAction::class)]
final class WorkerRuntimeQueueAction
{
    public static bool $handled = false;

    public function __invoke(WorkerRuntimeQueueMessage $message, CancellableMessageContextInterface $context): void
    {
        self::$handled = true;

        if ($context instanceof HeartbeatAwareMessageContextInterface) {
            $context->heartbeat();
        }

        $context->throwIfCancellationRequested();
    }
}

final class WorkerRuntimeQueueControl implements QueueJobControlInterface
{
    /** @var list<string> */
    public array $heartbeats = [];

    public function __construct(private readonly bool $cancellationRequested)
    {
    }

    public function cancel(string $queueMessageId): void
    {
    }

    public function requestCancellation(string $queueMessageId): void
    {
    }

    public function heartbeat(string $queueMessageId): void
    {
        $this->heartbeats[] = $queueMessageId;
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        return $this->cancellationRequested;
    }
}

final class WorkerRuntimeConsumer implements MessageConsumerInterface
{
    /** @var list<string> */
    public array $cancelled = [];

    public function __construct(private ?ReceivedQueueMessage $message)
    {
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        $message = $this->message;
        $this->message = null;

        return $message;
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
        $this->cancelled[] = $message->queueMessageId;
    }
}
