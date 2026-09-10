<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;

final class MessageBusEdgeCasesTest extends TestCase
{
    public function testContextlessSyncBindingsExecuteDirectlyInPriorityOrder(): void
    {
        MessageBusEdgeRecorder::$events = [];
        $registry = $this->compiledRegistry([
            MessageBusEdgeMessage::class,
            MessageBusEdgeLowPriorityHandler::class,
            MessageBusEdgeHighPriorityHandler::class,
        ]);
        $bus = $this->bus($registry);

        $result = $bus->dispatchAll(new MessageBusEdgeMessage('payload'));

        self::assertSame(['high:payload', 'low:payload'], MessageBusEdgeRecorder::$events);
        self::assertCount(2, $result->all());
    }

    public function testDispatchBindingSyncSupportsBackedEnumBindingAndRejectsWrongMessage(): void
    {
        MessageBusEdgeRecorder::$events = [];
        $registry = $this->compiledRegistry([
            MessageBusEdgeMessage::class,
            MessageBusEdgeLowPriorityHandler::class,
            MessageBusEdgeHighPriorityHandler::class,
        ]);
        $bus = $this->bus($registry);

        self::assertNull($bus->dispatchBindingSync(new MessageBusEdgeMessage('direct'), MessageBusEdgeBinding::High));
        self::assertSame(['high:direct'], MessageBusEdgeRecorder::$events);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Binding `edge.high` expects message `' . MessageBusEdgeMessage::class . '`, got `' . MessageBusWrongMessage::class . '`.');

        $bus->dispatchBindingSync(new MessageBusWrongMessage(), MessageBusEdgeBinding::High);
    }

    public function testDispatchEnvelopeToBindingRequiresBindingId(): void
    {
        $registry = $this->compiledRegistry([
            MessageBusEdgeMessage::class,
            MessageBusEdgeHighPriorityHandler::class,
        ]);
        $bus = $this->bus($registry);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Envelope bindingId is required for worker execution.');

        $bus->dispatchEnvelopeToBinding(new Envelope(
            new MessageBusEdgeMessage('queued'),
            'message-1',
            'correlation-1',
            null,
            'default',
            null,
            new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        ));
    }

    public function testFlowWithoutContextFactoryFailsAtRuntime(): void
    {
        $registry = $this->manualRegistry(
            FlowDefinition::sync('custom')->context(MessageBusEdgeContextInterface::class),
            HandlerBindingDefinition::event(
                MessageBusEdgeMessage::class,
                MessageBusEdgeContextAwareHandler::class,
                '__invoke',
                'custom',
                0,
                'edge.context_aware',
            ),
        );
        $bus = $this->bus($registry);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Flow `custom` has no context factory.');

        $bus->dispatchAll(new MessageBusEdgeMessage('payload'));
    }

    public function testContextFactoryMustReturnConfiguredContextInterface(): void
    {
        $flow = FlowDefinition::sync('custom')
            ->context(MessageBusEdgeContextInterface::class, MessageBusEdgeWrongContextFactory::class);
        $registry = $this->manualRegistry(
            $flow,
            HandlerBindingDefinition::event(
                MessageBusEdgeMessage::class,
                MessageBusEdgeContextAwareHandler::class,
                '__invoke',
                'custom',
                0,
                'edge.context_aware',
            ),
        );
        $bus = $this->bus($registry, new TestContainer([
            MessageBusEdgeWrongContextFactory::class => new MessageBusEdgeWrongContextFactory(),
            SequentialExecutionStrategy::class => new SequentialExecutionStrategy(),
        ], autowireClasses: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Flow `custom` context factory returned `' . DefaultMessageContext::class . '`, expected `' . MessageBusEdgeContextInterface::class . '`.');

        $bus->dispatchAll(new MessageBusEdgeMessage('payload'));
    }

    /**
     * @param list<class-string> $classes
     */
    private function compiledRegistry(array $classes): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry((new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            new FlowRegistry(),
            '6.0.0',
            'message-bus-edge-cases',
        ));
    }

    private function manualRegistry(FlowDefinition $flow, HandlerBindingDefinition $binding): CompiledMessageRegistry
    {
        $flows = new FlowRegistry($flow);

        return new CompiledMessageRegistry(new MessageRegistryDefinition(
            MessageRegistryCompiler::SCHEMA_VERSION,
            MessageRegistryCompiler::LIBRARY_VERSION,
            '2026-09-10T10:00:00+00:00',
            'message-bus-edge-cases',
            $flows,
            [MessageBusEdgeMessage::class => [$binding->bindingId]],
            [$binding->bindingId => $binding],
            [],
            [],
        ));
    }

    private function bus(CompiledMessageRegistry $registry, ?TestContainer $container = null): MessageBus
    {
        return new MessageBus(
            $registry,
            $registry->definition()->flows,
            $container ?? new TestContainer([], autowireClasses: true),
        );
    }
}

enum MessageBusEdgeBinding: string
{
    case High = 'edge.high';
}

final class MessageBusEdgeRecorder
{
    /** @var list<string> */
    public static array $events = [];
}

final class MessageBusEdgeMessage
{
    public function __construct(public readonly string $value)
    {
    }
}

final class MessageBusWrongMessage
{
}

#[EventSubscriber(
    message: MessageBusEdgeMessage::class,
    bindingId: 'edge.low',
    priority: 1,
    contextAware: false,
)]
final class MessageBusEdgeLowPriorityHandler
{
    public function __invoke(MessageBusEdgeMessage $message): void
    {
        MessageBusEdgeRecorder::$events[] = 'low:' . $message->value;
    }
}

#[EventSubscriber(
    message: MessageBusEdgeMessage::class,
    bindingId: 'edge.high',
    priority: 10,
    contextAware: false,
)]
final class MessageBusEdgeHighPriorityHandler
{
    public function __invoke(MessageBusEdgeMessage $message): void
    {
        MessageBusEdgeRecorder::$events[] = 'high:' . $message->value;
    }
}

interface MessageBusEdgeContextInterface extends MessageContextInterface
{
}

final class MessageBusEdgeContextAwareHandler
{
    public function __invoke(MessageBusEdgeMessage $message, MessageContextInterface $context): void
    {
    }
}

final class MessageBusEdgeWrongContextFactory implements MessageContextFactoryInterface
{
    public function create(
        MessageBusInterface $messageBus,
        Envelope $envelope,
        FlowDefinition $flow,
        ?WorkerRuntimeControlInterface $workerRuntimeControl = null,
    ): MessageContextInterface {
        return new DefaultMessageContext($messageBus, $envelope, $workerRuntimeControl);
    }
}
