<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Wolfcharaa\MessageBus\Builder\Builder;
use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\HandlerRegistry\ArrayHandlerRegistry;
use Wolfcharaa\MessageBus\HandlerRegistry\HandlerRegistryInterface;
use Wolfcharaa\MessageBus\HandlerRegistry\LazyHandlerRegistry;
use Wolfcharaa\MessageBus\HandlerRegistry\MessageDefinition;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Message\Event;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Middleware\QueueMiddleware;
use Wolfcharaa\MessageBus\Queue\QueueHeader;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;

final class EventQueueMiddlewareTest extends TestCase
{
    public function testLazyRegistryQueuesEventOnceAndWorkerRunsAllSubscribers(): void
    {
        $provider = new RecordingQueueProvider();
        $registry = $this->buildLazyRegistry($provider);

        $this->assertQueuedEventBehavior($registry, $provider);
    }

    public function testArrayRegistryQueuesEventOnceAndWorkerRunsAllSubscribers(): void
    {
        $provider = new RecordingQueueProvider();
        $registry = $this->buildArrayRegistry($provider);

        $this->assertQueuedEventBehavior($registry, $provider);
    }

    private function assertQueuedEventBehavior(HandlerRegistryInterface $registry, RecordingQueueProvider $provider): void
    {
        EventSubscriberOne::reset();
        EventSubscriberTwo::reset();

        $bus = new MessageBus($registry, null, null);

        $bus->dispatch(new ExampleEvent());

        self::assertSame(1, $provider->calls);
        self::assertCount(1, $provider->envelopes);
        self::assertSame(0, EventSubscriberOne::$calls);
        self::assertSame(0, EventSubscriberTwo::$calls);

        /** @var QueueHeader $queueHeader */
        $queueHeader = $provider->envelopes[0]->header->get(QueueHeader::class);
        self::assertFalse($queueHeader->isStarted);

        $queuedEnvelope = $provider->envelopes[0];
        $bus->dispatchEnvelope(new Envelope(
            $queuedEnvelope->message,
            $queuedEnvelope->messageId,
            $queuedEnvelope->causationId,
            $queuedEnvelope->correlationId,
            $queuedEnvelope->timestamp,
            $queuedEnvelope->header->with(QueueHeader::started())
        ));

        self::assertSame(1, $provider->calls);
        self::assertSame(1, EventSubscriberOne::$calls);
        self::assertSame(1, EventSubscriberTwo::$calls);
    }

    private function buildLazyRegistry(RecordingQueueProvider $provider): HandlerRegistryInterface
    {
        return new LazyHandlerRegistry(
            new Builder(new TestContainer($provider)),
            [QueueMiddleware::class],
            $this->buildDefinition()
        );
    }

    private function buildArrayRegistry(RecordingQueueProvider $provider): HandlerRegistryInterface
    {
        return new ArrayHandlerRegistry(
            new Builder(new TestContainer($provider)),
            [QueueMiddleware::class],
            $this->buildDefinition()
        );
    }

    private function buildDefinition(): MessageDefinition
    {
        return (new MessageDefinition(ExampleEvent::class))
            ->setIsEvent(true)
            ->setShouldQueue()
            ->setHandlerFactory([EventSubscriberOne::class])
            ->setHandlerFactory([EventSubscriberTwo::class]);
    }
}

final class RecordingQueueProvider implements QueueProviderInterface
{
    public int $calls = 0;

    /** @var list<Envelope> */
    public array $envelopes = [];

    public function enqueue(Envelope $envelope): void
    {
        ++$this->calls;
        $this->envelopes[] = $envelope;
    }
}

final class TestContainer implements ContainerInterface
{
    private RecordingQueueProvider $provider;

    public function __construct(RecordingQueueProvider $provider)
    {
        $this->provider = $provider;
    }

    public function get(string $id)
    {
        if ($id === QueueMiddleware::class) {
            return new QueueMiddleware($this->provider);
        }

        return new $id();
    }

    public function has(string $id)
    {
        return true;
    }
}

final class ExampleEvent implements Event
{
}

final class EventSubscriberOne
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function __invoke(ExampleEvent $event, Context $context): void
    {
        ++self::$calls;
    }
}

final class EventSubscriberTwo
{
    public static int $calls = 0;

    public static function reset(): void
    {
        self::$calls = 0;
    }

    public function __invoke(ExampleEvent $event, Context $context): void
    {
        ++self::$calls;
    }
}
