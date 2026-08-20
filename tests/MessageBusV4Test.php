<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Dumper\SymfonyVarExporterRegistryDumper;
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Message\IncrementalMessageIdGenerator;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\MessageBusQueueWorker;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class MessageBusV4Test extends TestCase
{
    public function testDispatchReturnsPrimarySyncResult(): void
    {
        $registry = $this->registry([
            CreateUserMessage::class,
            CreateUserAction::class,
        ]);

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $result = $bus->dispatch(new CreateUserMessage('user@example.com'));

        self::assertInstanceOf(CreateUserResult::class, $result);
        self::assertSame('user@example.com', $result->email);
        self::assertSame('1', $result->messageId);
        self::assertSame('1', $result->correlationId);
        self::assertNull($result->causationId);
    }

    public function testNestedDispatchKeepsCausationAndCorrelation(): void
    {
        $registry = $this->registry([
            ParentMessage::class,
            ParentAction::class,
            ChildMessage::class,
            ChildAction::class,
        ]);

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $result = $bus->dispatch(new ParentMessage());

        self::assertSame('1', $result['parentMessageId']);
        self::assertSame('2', $result['childMessageId']);
        self::assertSame('1', $result['childCausationId']);
        self::assertSame('1', $result['childCorrelationId']);
    }

    public function testDispatchAllReturnsAllSyncHandlerResults(): void
    {
        $registry = $this->registry([
            MultiSyncMessage::class,
            MultiSyncPrimaryAction::class,
            MultiSyncSecondaryAction::class,
        ]);

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $result = $bus->dispatchAll(new MultiSyncMessage('value'));

        self::assertSame('primary:value', $result->getByAction(MultiSyncPrimaryAction::class));
        self::assertSame('secondary:value', $result->getByAction(MultiSyncSecondaryAction::class));
    }

    public function testPublishCreatesQueueMessagePerAsyncBinding(): void
    {
        $provider = new RecordingQueueProvider();
        $flow = FlowDefinition::async('notifications')
            ->transport('database', 'notifications');
        $registry = $this->registry([
            UserCreatedEvent::class,
            SendWelcomeEmailAction::class,
            WriteUserAuditAction::class,
        ], new FlowRegistry($flow));

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $bus->publish(new UserCreatedEvent(7), new PublishOptions(headers: Headers::empty()->with(TestHeaderKey::RequestId, 'request-1')));

        self::assertCount(2, $provider->messages);
        self::assertSame('user.created.send_welcome_email', $provider->messages[0]->bindingId);
        self::assertSame('user.created.write_audit', $provider->messages[1]->bindingId);
        self::assertSame('database', $provider->messages[0]->transport);
        self::assertSame('notifications', $provider->messages[0]->queue);
        self::assertSame('user.created', $provider->messages[0]->envelope->message->name);
        self::assertSame('{"userId":7}', $provider->messages[0]->envelope->message->payload);
        self::assertSame('request-1', $provider->messages[0]->envelope->headers['request_id']);
    }

    public function testCompilerReadsMessageAliasFromHandlerBindingMessage(): void
    {
        $flow = FlowDefinition::async('notifications')
            ->transport('database', 'notifications');

        $registry = $this->registry([
            SendWelcomeEmailAction::class,
        ], new FlowRegistry($flow));

        self::assertSame(UserCreatedEvent::class, $registry->messageClassForName('user.created'));
        self::assertSame('user.created', $registry->messageName(UserCreatedEvent::class));
    }

    public function testBindingDeliveryDoesNotResetFlowPriorityWhenOnlyDelayIsDeclared(): void
    {
        $provider = new RecordingQueueProvider();
        $flow = FlowDefinition::async('delayed')
            ->transport('database', 'delayed')
            ->delivery(new \Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions(priority: 5));
        $registry = $this->registry([
            DelayedEventAction::class,
        ], new FlowRegistry($flow));

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $bus->publish(new DelayedEvent(3));

        self::assertCount(1, $provider->messages);
        self::assertSame(5, $provider->messages[0]->priority);
        self::assertSame('2026-08-18T12:00:30+00:00', $provider->messages[0]->availableAt->format(DATE_ATOM));
    }

    public function testQueueWorkerExecutesSerializedEnvelopeBinding(): void
    {
        $provider = new RecordingQueueProvider();
        $flow = FlowDefinition::async('jobs')
            ->transport('database', 'jobs');
        $registry = $this->registry([
            AsyncCommandMessage::class,
            AsyncCommandAction::class,
        ], new FlowRegistry($flow));
        $serializer = new DefaultEnvelopeSerializer(new JsonMessageSerializer($registry));
        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            envelopeSerializer: $serializer,
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $bus->publish(new AsyncCommandMessage(44));
        $worker = new MessageBusQueueWorker($bus, $serializer);

        self::assertSame('processed:44', $worker->handle($provider->messages[0]->envelope));
    }

    public function testDispatchPublishedSyncRunsAsyncBindingsWithoutQueue(): void
    {
        AsyncRecorder::$calls = [];
        $flow = FlowDefinition::async('notifications')
            ->transport('database', 'notifications');
        $registry = $this->registry([
            UserCreatedEvent::class,
            SendWelcomeEmailAction::class,
            WriteUserAuditAction::class,
        ], new FlowRegistry($flow));

        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        $bus->dispatchPublishedSync(new UserCreatedEvent(10));

        self::assertSame(['email:10', 'audit:10'], AsyncRecorder::$calls);
    }

    public function testCompiledRegistryCanBeDumpedAndLoaded(): void
    {
        $definition = (new MessageRegistryCompiler())->compile(
            new ClassListProvider([
                CreateUserMessage::class,
                CreateUserAction::class,
            ]),
            null,
            '4.0.0',
            'test',
        );
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-registry-');
        self::assertIsString($file);

        \file_put_contents($file, (new SymfonyVarExporterRegistryDumper())->dump($definition));

        try {
            $registry = CompiledMessageRegistry::fromFile($file);
            $bus = new MessageBus(
                $registry,
                $registry->definition()->flows,
            new TestContainer(),
                messageIdGenerator: new IncrementalMessageIdGenerator(),
                clock: new FrozenClock(),
            );

            self::assertInstanceOf(CreateUserResult::class, $bus->dispatch(new CreateUserMessage('compiled@example.com')));
        } finally {
            @\unlink($file);
        }
    }

    public function testFlowContextFactoryCanProvideCustomContext(): void
    {
        $flow = FlowDefinition::sync('custom')
            ->context(TestMessageContextInterface::class, TestMessageContextFactory::class);
        $registry = $this->registry([
            CustomContextMessage::class,
            CustomContextAction::class,
        ], new FlowRegistry($flow));
        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        self::assertSame('custom-context:payload', $bus->dispatch(new CustomContextMessage('payload')));
    }

    public function testFlowAndBindingMiddlewareAreMergedInOrder(): void
    {
        MiddlewareRecorder::$events = [];
        $flow = FlowDefinition::sync('default')->middleware(FlowMiddleware::class);
        $registry = $this->registry([
            MiddlewareMessage::class,
            MiddlewareAction::class,
        ], new FlowRegistry($flow));
        $bus = new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            messageIdGenerator: new IncrementalMessageIdGenerator(),
            clock: new FrozenClock(),
        );

        self::assertSame('middleware-done', $bus->dispatch(new MiddlewareMessage()));
        self::assertSame([
            'flow-before',
            'binding-before',
            'handler',
            'binding-after',
            'flow-after',
        ], MiddlewareRecorder::$events);
    }

    public function testCompilerRequiresAliasAndBindingIdForAsyncBindings(): void
    {
        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('stable bindingId');

        $this->registry([
            BadAsyncEvent::class,
            BadAsyncAction::class,
        ], new FlowRegistry(FlowDefinition::async('bad')->transport('database', 'bad')));
    }

    public function testCompilerRejectsInvalidQueryReturnType(): void
    {
        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('cannot return void');

        $this->registry([
            BadQueryMessage::class,
            BadQueryAction::class,
        ]);
    }

    public function testCompilerRejectsDuplicateMessageAlias(): void
    {
        $this->expectException(RegistryCompilationException::class);
        $this->expectExceptionMessage('Duplicate MessageAlias');

        $this->registry([
            DuplicateAliasFirstMessage::class,
            DuplicateAliasSecondMessage::class,
        ]);
    }

    public function testJsonSerializerRejectsObjectsInPayload(): void
    {
        $registry = $this->registry([
            ObjectPayloadMessage::class,
        ]);
        $serializer = new JsonMessageSerializer($registry);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scalar, array and null');

        $serializer->serialize(new ObjectPayloadMessage(new DateTimeImmutable()));
    }

    /** @param list<class-string> $classes */
    private function registry(array $classes, ?FlowRegistry $flows = null): CompiledMessageRegistry
    {
        $definition = (new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            $flows,
            '4.0.0',
            'test',
        );

        return new CompiledMessageRegistry($definition);
    }
}

#[MessageAlias('duplicate.alias')]
final class DuplicateAliasFirstMessage
{
}

#[MessageAlias('duplicate.alias')]
final class DuplicateAliasSecondMessage
{
}

enum TestHeaderKey: string
{
    case RequestId = 'request_id';
}

final class FrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-18T12:00:00+00:00');
    }
}

final class RecordingQueueProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        return new QueueEnqueueResult('queue-' . \count($this->messages));
    }
}

/**
 * @implements Command<CreateUserResult>
 */
final class CreateUserMessage implements Command
{
    public function __construct(public readonly string $email)
    {
    }
}

final class CreateUserResult
{
    public function __construct(
        public readonly string $email,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly ?string $causationId,
    ) {
    }
}

#[CommandHandler(message: CreateUserMessage::class)]
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, \Wolfcharaa\MessageBus\Context\MessageContextInterface $context): CreateUserResult
    {
        return new CreateUserResult(
            $message->email,
            $context->envelope()->messageId,
            $context->envelope()->correlationId,
            $context->envelope()->causationId,
        );
    }
}

final class ParentMessage
{
}

#[CommandHandler(message: ParentMessage::class)]
final class ParentAction
{
    public function __invoke(ParentMessage $message, \Wolfcharaa\MessageBus\Context\MessageContextInterface $context): array
    {
        $child = $context->dispatch(new ChildMessage());

        return [
            'parentMessageId' => $context->envelope()->messageId,
            ...$child,
        ];
    }
}

final class ChildMessage
{
}

#[CommandHandler(message: ChildMessage::class)]
final class ChildAction
{
    public function __invoke(ChildMessage $message, MessageContextInterface $context): array
    {
        return [
            'childMessageId' => $context->envelope()->messageId,
            'childCausationId' => $context->envelope()->causationId,
            'childCorrelationId' => $context->envelope()->correlationId,
        ];
    }
}

final class MultiSyncMessage
{
    public function __construct(public readonly string $value)
    {
    }
}

#[CommandHandler(message: MultiSyncMessage::class, primary: true)]
final class MultiSyncPrimaryAction
{
    public function __invoke(MultiSyncMessage $message, MessageContextInterface $context): string
    {
        return 'primary:' . $message->value;
    }
}

#[CommandHandler(message: MultiSyncMessage::class, primary: false)]
final class MultiSyncSecondaryAction
{
    public function __invoke(MultiSyncMessage $message, MessageContextInterface $context): string
    {
        return 'secondary:' . $message->value;
    }
}

#[MessageAlias('user.created')]
final class UserCreatedEvent
{
    public function __construct(public readonly int $userId)
    {
    }
}

final class AsyncRecorder
{
    /** @var list<string> */
    public static array $calls = [];
}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'notifications',
    bindingId: 'user.created.send_welcome_email',
)]
final class SendWelcomeEmailAction
{
    public function __invoke(UserCreatedEvent $message, \Wolfcharaa\MessageBus\Context\MessageContextInterface $context): void
    {
        AsyncRecorder::$calls[] = 'email:' . $message->userId;
    }
}

#[EventSubscriber(
    message: UserCreatedEvent::class,
    flow: 'notifications',
    bindingId: 'user.created.write_audit',
)]
final class WriteUserAuditAction
{
    public function __invoke(UserCreatedEvent $message, MessageContextInterface $context): void
    {
        AsyncRecorder::$calls[] = 'audit:' . $message->userId;
    }
}

#[MessageAlias('async.command')]
final class AsyncCommandMessage
{
    public function __construct(public readonly int $id)
    {
    }
}

#[CommandHandler(
    message: AsyncCommandMessage::class,
    flow: 'jobs',
    bindingId: 'async.command.process',
)]
final class AsyncCommandAction
{
    public function __invoke(AsyncCommandMessage $message, MessageContextInterface $context): string
    {
        return 'processed:' . $message->id;
    }
}

#[MessageAlias('bad.async')]
final class BadAsyncEvent
{
}

#[EventSubscriber(message: BadAsyncEvent::class, flow: 'bad')]
final class BadAsyncAction
{
    public function __invoke(BadAsyncEvent $message, MessageContextInterface $context): void
    {
    }
}

final class BadQueryMessage
{
}

#[QueryHandler(message: BadQueryMessage::class)]
final class BadQueryAction
{
    public function __invoke(BadQueryMessage $message, MessageContextInterface $context): void
    {
    }
}

interface TestMessageContextInterface extends MessageContextInterface
{
    public function prefix(): string;
}

final class TestMessageContext implements TestMessageContextInterface
{
    private readonly DefaultMessageContext $inner;

    public function __construct(MessageBusInterface $bus, Envelope $envelope)
    {
        $this->inner = new DefaultMessageContext($bus, $envelope);
    }

    public function prefix(): string
    {
        return 'custom-context';
    }

    public function envelope(): Envelope
    {
        return $this->inner->envelope();
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        return $this->inner->dispatch($message, $options);
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        return $this->inner->dispatchAll($message, $options);
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): \Wolfcharaa\MessageBus\PublishResult
    {
        return $this->inner->publish($message, $options);
    }
}

final class TestMessageContextFactory implements MessageContextFactoryInterface
{
    public function create(MessageBusInterface $bus, Envelope $envelope, FlowDefinition $flow): MessageContextInterface
    {
        return new TestMessageContext($bus, $envelope);
    }
}

final class CustomContextMessage
{
    public function __construct(public readonly string $value)
    {
    }
}

#[CommandHandler(message: CustomContextMessage::class, flow: 'custom')]
final class CustomContextAction
{
    public function __invoke(CustomContextMessage $message, TestMessageContextInterface $context): string
    {
        return $context->prefix() . ':' . $message->value;
    }
}

final class MiddlewareRecorder
{
    /** @var list<string> */
    public static array $events = [];
}

final class MiddlewareMessage
{
}

final class FlowMiddleware
{
    public function __invoke(MessageContextInterface $context, \Wolfcharaa\MessageBus\Middleware\PipelineInterface $pipeline): mixed
    {
        MiddlewareRecorder::$events[] = 'flow-before';
        $result = $pipeline->continue();
        MiddlewareRecorder::$events[] = 'flow-after';

        return $result;
    }
}

final class BindingMiddleware
{
    public function __invoke(MessageContextInterface $context, \Wolfcharaa\MessageBus\Middleware\PipelineInterface $pipeline): mixed
    {
        MiddlewareRecorder::$events[] = 'binding-before';
        $result = $pipeline->continue();
        MiddlewareRecorder::$events[] = 'binding-after';

        return $result;
    }
}

#[CommandHandler(message: MiddlewareMessage::class, middleware: [BindingMiddleware::class])]
final class MiddlewareAction
{
    public function __invoke(MiddlewareMessage $message, MessageContextInterface $context): string
    {
        MiddlewareRecorder::$events[] = 'handler';

        return 'middleware-done';
    }
}

#[MessageAlias('delayed.event')]
final class DelayedEvent
{
    public function __construct(public readonly int $id)
    {
    }
}

#[EventSubscriber(
    message: DelayedEvent::class,
    flow: 'delayed',
    bindingId: 'delayed.event.notify',
    delaySeconds: 30,
)]
final class DelayedEventAction
{
    public function __invoke(DelayedEvent $message, MessageContextInterface $context): void
    {
    }
}

#[MessageAlias('object.payload')]
final class ObjectPayloadMessage
{
    public function __construct(public readonly DateTimeImmutable $date)
    {
    }
}
