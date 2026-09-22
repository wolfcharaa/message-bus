<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\MessageAlias;
use Wolfcharaa\MessageBus\Attribute\SagaHandler;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Message\MessageIdGenerator;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusFanOutInterface;
use Wolfcharaa\MessageBus\PublishFailed;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Registry\BindingNotFound;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class MessageBusFanOutTest extends TestCase
{
    public function testDispatchWithFanOutExecutesCommandAndRegistersAsyncBindings(): void
    {
        FanOutRecorder::reset();
        $provider = new FanOutQueueProvider();
        $registry = $this->registry([
            FanOutCommand::class,
            FanOutSagaHandler::class,
            FanOutAuditCommandHandler::class,
        ]);
        $bus = $this->bus($registry, $provider);

        self::assertInstanceOf(MessageBusFanOutInterface::class, $bus);

        $result = $bus->dispatchWithFanOut(new FanOutCommand('42'));

        self::assertNull($result->dispatchResult);
        self::assertFalse($result->fanOutResult->isEmpty());
        self::assertSame(['saga:42:fanout-message-1:fanout-message-1'], FanOutRecorder::$events);
        self::assertCount(1, $provider->messages);
        self::assertSame('fanout.audit', $provider->messages[0]->bindingId);
        self::assertSame('fanout-message-1', $provider->messages[0]->messageId);
        self::assertSame('fanout-message-1', $provider->messages[0]->correlationId);
    }

    public function testDispatchWithFanOutFailsBeforeDispatchWhenMessageHasNoAsyncBindings(): void
    {
        FanOutRecorder::reset();
        $registry = $this->registry([
            FanOutCommand::class,
            FanOutSagaHandler::class,
        ]);
        $bus = $this->bus($registry, new FanOutQueueProvider());

        $this->expectException(BindingNotFound::class);
        $this->expectExceptionMessage('Message `' . FanOutCommand::class . '` has no async fan-out bindings.');

        try {
            $bus->dispatchWithFanOut(new FanOutCommand('42'));
        } finally {
            self::assertSame([], FanOutRecorder::$events);
        }
    }

    public function testDispatchWithFanOutPropagatesPublishFailureWhenAsyncRegistrationFails(): void
    {
        FanOutRecorder::reset();
        $provider = new FanOutFailingQueueProvider();
        $registry = $this->registry([
            FanOutCommand::class,
            FanOutSagaHandler::class,
            FanOutAuditCommandHandler::class,
        ]);
        $bus = $this->bus($registry, $provider);

        $this->expectException(PublishFailed::class);

        try {
            $bus->dispatchWithFanOut(new FanOutCommand('42'));
        } finally {
            self::assertSame(['saga:42:fanout-message-1:fanout-message-1'], FanOutRecorder::$events);
        }
    }

    /**
     * @param list<class-string> $classes
     */
    private function registry(array $classes): CompiledMessageRegistry
    {
        $flows = new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('memory', 'fanout'),
        );

        $definition = (new MessageRegistryCompiler())->compile(
            new ClassListProvider($classes),
            $flows,
        );

        return new CompiledMessageRegistry($definition);
    }

    private function bus(CompiledMessageRegistry $registry, QueueProviderInterface $provider): MessageBus
    {
        return new MessageBus(
            $registry,
            $registry->definition()->flows,
            new TestContainer(),
            queueProvider: $provider,
            messageIdGenerator: new FanOutMessageIdGenerator(),
        );
    }
}

#[MessageAlias('tests.fanout.command')]
final readonly class FanOutCommand implements Command
{
    public function __construct(public string $id)
    {
    }
}

#[SagaHandler(message: FanOutCommand::class)]
final class FanOutSagaHandler
{
    public function __invoke(FanOutCommand $message, MessageContextInterface $context): void
    {
        FanOutRecorder::$events[] = \sprintf(
            'saga:%s:%s:%s',
            $message->id,
            $context->envelope()->messageId,
            $context->envelope()->correlationId,
        );
    }
}

#[CommandHandler(message: FanOutCommand::class, flow: 'async', bindingId: 'fanout.audit')]
final class FanOutAuditCommandHandler
{
    public function __invoke(FanOutCommand $message, MessageContextInterface $context): void
    {
        FanOutRecorder::$events[] = 'audit:' . $message->id;
    }
}

final class FanOutQueueProvider implements QueueProviderInterface
{
    /** @var list<QueueMessage> */
    public array $messages = [];

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        $this->messages[] = $message;

        return new QueueEnqueueResult('fanout-queue-' . \count($this->messages));
    }
}

final class FanOutFailingQueueProvider implements QueueProviderInterface
{
    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        throw new RuntimeException('fan-out queue is unavailable');
    }
}

final class FanOutMessageIdGenerator implements MessageIdGenerator
{
    private int $next = 0;

    public function generate(): string
    {
        ++$this->next;

        return 'fanout-message-' . $this->next;
    }
}

final class FanOutRecorder
{
    /** @var list<string> */
    public static array $events = [];

    public static function reset(): void
    {
        self::$events = [];
    }
}
