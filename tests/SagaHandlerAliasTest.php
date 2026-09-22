<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Attribute\EventSubscriber;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\SagaHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Message\MessageIdGenerator;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerKind;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class SagaHandlerAliasTest extends TestCase
{
    public function testSagaHandlerAliasCompilesAndDispatchesAsCommandHandler(): void
    {
        SagaAliasRecorder::reset();
        $provider = new SagaAliasQueueProvider();
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);

        $bindings = $registry->bindingsForMessage(SagaAliasCommand::class);
        $commandBindings = \array_values(\array_filter(
            $bindings,
            static fn ($binding): bool => $binding->kind === HandlerKind::Command,
        ));

        self::assertCount(1, $commandBindings);
        self::assertSame(SagaAliasHandler::class, $commandBindings[0]->action);
        self::assertTrue($commandBindings[0]->primary);

        self::assertNull($bus->dispatch(new SagaAliasCommand('42')));
        self::assertSame(['saga:42'], SagaAliasRecorder::$events);
        self::assertSame([], $provider->messages);
    }

    public function testPublishStillControlsAsyncEventFanOutExplicitly(): void
    {
        SagaAliasRecorder::reset();
        $provider = new SagaAliasQueueProvider();
        $registry = $this->registry();
        $bus = $this->bus($registry, $provider);

        $result = $bus->publish(new SagaAliasCommand('42'));

        self::assertSame([], SagaAliasRecorder::$events);
        self::assertCount(1, $provider->messages);
        self::assertSame('saga.alias.audit', $provider->messages[0]->bindingId);
        self::assertFalse($result->isEmpty());
    }

    private function registry(): CompiledMessageRegistry
    {
        $flows = new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('memory', 'events'),
        );

        $definition = (new MessageRegistryCompiler())->compile(
            new ClassListProvider([
                SagaAliasCommand::class,
                SagaAliasHandler::class,
                SagaAliasAuditSubscriber::class,
            ]),
            $flows,
        );

        return new CompiledMessageRegistry($definition);
    }

    private function bus(CompiledMessageRegistry $registry, SagaAliasQueueProvider $provider): MessageBus
    {
        return new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            messageIdGenerator: new SagaAliasMessageIdGenerator(),
        );
    }
}

#[MessageAlias('tests.saga_alias.command')]
final readonly class SagaAliasCommand implements Command
{
    public function __construct(public string $id)
    {
    }
}

#[SagaHandler(message: SagaAliasCommand::class)]
final class SagaAliasHandler
{
    public function __invoke(SagaAliasCommand $message, MessageContextInterface $context): void
    {
        SagaAliasRecorder::$events[] = 'saga:' . $message->id;
    }
}

#[EventSubscriber(message: SagaAliasCommand::class, flow: 'async', bindingId: 'saga.alias.audit')]
final class SagaAliasAuditSubscriber
{
    public function __invoke(SagaAliasCommand $message, MessageContextInterface $context): void
    {
        SagaAliasRecorder::$events[] = 'audit:' . $message->id;
    }
}

final class SagaAliasQueueProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        return new QueueEnqueueResult('queue-' . \count($this->messages));
    }
}

final class SagaAliasMessageIdGenerator implements MessageIdGenerator
{
    private int $next = 0;

    public function generate(): string
    {
        ++$this->next;

        return 'saga-message-' . $this->next;
    }
}

final class SagaAliasRecorder
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}
