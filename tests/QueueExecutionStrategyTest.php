<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Execution\ExecutionEnvironment;
use Wolfcharaa\MessageBus\Execution\ExecutionRequest;
use Wolfcharaa\MessageBus\Execution\QueueExecutionStrategy;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\BatchQueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueFailed;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\RetryPolicy;
use Wolfcharaa\MessageBus\Queue\RetryPolicyRegistryInterface;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class QueueExecutionStrategyTest extends TestCase
{
    public function testSingleProviderEnqueuesMessagesWithMergedDeliveryOptionsAndRetryPolicy(): void
    {
        $provider = new QueueExecutionRecordingProvider();
        $strategy = new QueueExecutionStrategy();
        $request = $this->request(
            [
                HandlerBindingDefinition::event(
                    QueueExecutionMessage::class,
                    QueueExecutionHandler::class,
                    '__invoke',
                    'async',
                    0,
                    'queue.execution.one',
                    delivery: new QueueDeliveryOptions(priority: 5),
                ),
            ],
            FlowDefinition::async('async')
                ->transport('postgres', 'mail')
                ->delivery(new QueueDeliveryOptions(priority: 1, delaySeconds: 30, retryPolicy: 'flow-default')),
            new PublishOptions(delivery: new QueueDeliveryOptions(delaySeconds: 7, retryPolicy: 'fast')),
            $provider,
            new QueueExecutionRetryPolicyRegistry(),
        );

        $result = $strategy->execute($request);

        self::assertFalse($result->hasFailures());
        self::assertCount(1, $provider->messages);
        self::assertSame('postgres', $provider->messages[0]->transport);
        self::assertSame('mail', $provider->messages[0]->queue);
        self::assertSame('queue.execution.one', $provider->messages[0]->bindingId);
        self::assertSame(5, $provider->messages[0]->priority);
        self::assertSame('fast', $provider->messages[0]->retryPolicyKey);
        self::assertSame(['delaySeconds' => 2], $provider->messages[0]->retryPolicySnapshot->parameters);
        self::assertSame('2026-09-11T10:00:07+00:00', $provider->messages[0]->availableAt->format(DATE_ATOM));
        self::assertSame('queue-1', $result->get('queue.execution.one')->queueMessageId);
    }

    public function testSingleProviderCapturesEnqueueFailurePerBinding(): void
    {
        $provider = new QueueExecutionRecordingProvider(fail: true);
        $result = (new QueueExecutionStrategy())->execute($this->request(
            [
                HandlerBindingDefinition::event(
                    QueueExecutionMessage::class,
                    QueueExecutionHandler::class,
                    '__invoke',
                    'async',
                    0,
                    'queue.execution.failure',
                ),
            ],
            provider: $provider,
        ));

        self::assertTrue($result->hasFailures());
        $failure = $result->failed()[0]->error();
        self::assertInstanceOf(QueueEnqueueFailed::class, $failure);
        self::assertSame('queue.execution.failure', $failure->queueMessage->bindingId);
        self::assertSame('enqueue failed', $failure->getPrevious()?->getMessage());
    }

    public function testBatchProviderMapsBatchResultsToBindings(): void
    {
        $provider = new QueueExecutionBatchProvider();
        $result = (new QueueExecutionStrategy())->execute($this->request([
            HandlerBindingDefinition::event(
                QueueExecutionMessage::class,
                QueueExecutionHandler::class,
                '__invoke',
                'async',
                10,
                'queue.execution.first',
            ),
            HandlerBindingDefinition::event(
                QueueExecutionMessage::class,
                QueueExecutionOtherHandler::class,
                '__invoke',
                'async',
                0,
                'queue.execution.second',
            ),
        ], provider: $provider));

        self::assertFalse($result->hasFailures());
        self::assertCount(2, $provider->messages);
        self::assertSame('batch-1', $result->get('queue.execution.first')->queueMessageId);
        self::assertSame('batch-2', $result->get('queue.execution.second')->queueMessageId);
    }

    public function testBatchProviderTurnsUnexpectedResultCountIntoPerBindingFailures(): void
    {
        $provider = new QueueExecutionBatchProvider(resultCount: 1);
        $result = (new QueueExecutionStrategy())->execute($this->request([
            HandlerBindingDefinition::event(
                QueueExecutionMessage::class,
                QueueExecutionHandler::class,
                '__invoke',
                'async',
                0,
                'queue.execution.first',
            ),
            HandlerBindingDefinition::event(
                QueueExecutionMessage::class,
                QueueExecutionOtherHandler::class,
                '__invoke',
                'async',
                0,
                'queue.execution.second',
            ),
        ], provider: $provider));

        self::assertTrue($result->hasFailures());
        self::assertCount(2, $result->failed());
        self::assertSame('Batch queue provider returned unexpected number of enqueue results.', $result->failed()[0]->error()?->getPrevious()?->getMessage());
    }

    public function testExecuteRequiresQueueProviderTransportAndStableBindingId(): void
    {
        $strategy = new QueueExecutionStrategy();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Queue provider is required for async flow execution.');

        $strategy->execute($this->request(provider: null));
    }

    public function testExecuteRejectsAsyncFlowWithoutTransport(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Async flow `async` has no transport configuration.');

        (new QueueExecutionStrategy())->execute($this->request(flow: FlowDefinition::async('async')));
    }

    public function testExecuteRejectsAsyncBindingWithoutStableBindingId(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Async binding must have stable bindingId.');

        (new QueueExecutionStrategy())->execute($this->request([
            HandlerBindingDefinition::event(
                QueueExecutionMessage::class,
                QueueExecutionHandler::class,
                '__invoke',
                'async',
                0,
            ),
        ]));
    }

    public function testCustomRetryPolicyRequiresRegistry(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Retry policy `fast` requires retry policy registry.');

        (new QueueExecutionStrategy())->execute($this->request(
            flow: FlowDefinition::async('async')
                ->transport('postgres', 'mail')
                ->delivery(new QueueDeliveryOptions(retryPolicy: 'fast')),
        ));
    }

    /**
     * @param non-empty-list<HandlerBindingDefinition>|null $bindings
     */
    private function request(
        ?array $bindings = null,
        ?FlowDefinition $flow = null,
        ?PublishOptions $options = null,
        ?QueueProviderInterface $provider = new QueueExecutionRecordingProvider(),
        ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
    ): ExecutionRequest {
        $message = new QueueExecutionMessage();
        $flow ??= FlowDefinition::async('async')->transport('postgres', 'default');
        $envelope = new Envelope(
            $message,
            'message-1',
            'correlation-1',
            null,
            $flow->key,
            null,
            new DateTimeImmutable('2026-09-11T09:59:00+00:00'),
            Headers::empty()->with('source', 'queue-execution-test'),
        );

        return new ExecutionRequest(
            $bindings ?? [
                HandlerBindingDefinition::event(
                    QueueExecutionMessage::class,
                    QueueExecutionHandler::class,
                    '__invoke',
                    'async',
                    0,
                    'queue.execution.default',
                ),
            ],
            new DefaultMessageContext(new QueueExecutionBus(), $envelope),
            $flow,
            $options ?? new PublishOptions(),
            new ExecutionEnvironment(
                new QueueExecutionInvoker(),
                new QueueExecutionEnvelopeSerializer(),
                new QueueExecutionClock(),
                $provider,
                $retryPolicyRegistry,
            ),
        );
    }
}

final class QueueExecutionMessage
{
}

final class QueueExecutionHandler
{
}

final class QueueExecutionOtherHandler
{
}

final class QueueExecutionRecordingProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function __construct(private readonly bool $fail = false)
    {
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        if ($this->fail) {
            throw new LogicException('enqueue failed');
        }

        return new QueueEnqueueResult('queue-' . \count($this->messages));
    }
}

final class QueueExecutionBatchProvider implements BatchQueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function __construct(private readonly ?int $resultCount = null)
    {
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        return new QueueEnqueueResult('queue-' . \count($this->messages));
    }

    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        $this->messages = \is_array($messages) ? \array_values($messages) : \iterator_to_array($messages, false);
        $count = $this->resultCount ?? \count($this->messages);
        $results = [];

        for ($index = 1; $index <= $count; $index++) {
            $results[] = new QueueEnqueueResult('batch-' . $index);
        }

        return new QueueBatchEnqueueResult(...$results);
    }
}

final class QueueExecutionRetryPolicyRegistry implements RetryPolicyRegistryInterface
{
    public function get(string $key): RetryPolicy
    {
        TestCase::assertSame('fast', $key);

        return RetryPolicy::fixed(5, 2);
    }
}

final class QueueExecutionEnvelopeSerializer implements EnvelopeSerializerInterface
{
    public function serialize(Envelope $envelope): SerializedEnvelope
    {
        return new SerializedEnvelope(
            new SerializedMessage($envelope->message::class, 'application/json', '{}'),
            $envelope->headers->all(),
            $envelope->messageId,
            $envelope->causationId,
            $envelope->correlationId,
            $envelope->flow,
            $envelope->bindingId,
            $envelope->createdAt,
        );
    }

    public function deserialize(SerializedEnvelope $envelope): Envelope
    {
        throw new LogicException('Not used by queue execution strategy tests.');
    }
}

final class QueueExecutionClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-11T10:00:00+00:00');
    }
}

final class QueueExecutionInvoker implements CallableInvokerInterface
{
    public function invoke(string|object $service, string $method, array $arguments): mixed
    {
        throw new LogicException('Not used by queue execution strategy tests.');
    }
}

final class QueueExecutionBus implements MessageBusInterface
{
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): \Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): \Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|\BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by queue execution strategy tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new LogicException('Not used by queue execution strategy tests.');
    }
}
