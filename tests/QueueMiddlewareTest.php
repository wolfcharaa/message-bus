<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Header;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Middleware\QueueMiddleware;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\Queue\QueueHeader;

final class QueueMiddlewareTest extends TestCase
{
    public function testContinuesPipelineWhenMessageIsNotQueued(): void
    {
        $provider = new RecordingQueueProvider();
        $middleware = new QueueMiddleware($provider);
        $handler = new RecordingHandler('handled');
        $context = new Context(new NullMessageBus(), new Envelope(new \stdClass(), 'message-id'));

        $result = $middleware->handle($context, new Pipeline($handler, $context, []));

        self::assertSame('handled', $result);
        self::assertSame(1, $handler->calls);
        self::assertSame(0, $provider->calls);
    }

    public function testEnqueuesMessageWhenQueueHeaderIsNotStarted(): void
    {
        $provider = new RecordingQueueProvider();
        $middleware = new QueueMiddleware($provider);
        $handler = new RecordingHandler('handled');
        $envelope = new Envelope(new \stdClass(), 'message-id', null, null, null, new Header(new QueueHeader()));
        $context = new Context(new NullMessageBus(), $envelope);

        $result = $middleware->handle($context, new Pipeline($handler, $context, []));

        self::assertNull($result);
        self::assertSame(0, $handler->calls);
        self::assertSame(1, $provider->calls);
        self::assertSame($envelope, $provider->envelopes[0]);
    }

    public function testContinuesPipelineWhenQueueMessageStartedByWorker(): void
    {
        $provider = new RecordingQueueProvider();
        $middleware = new QueueMiddleware($provider);
        $handler = new RecordingHandler('handled');
        $envelope = new Envelope(
            new \stdClass(),
            'message-id',
            null,
            null,
            null,
            new Header(QueueHeader::started())
        );
        $context = new Context(new NullMessageBus(), $envelope);

        $result = $middleware->handle($context, new Pipeline($handler, $context, []));

        self::assertSame('handled', $result);
        self::assertSame(1, $handler->calls);
        self::assertSame(0, $provider->calls);
    }
}

final class RecordingHandler implements Handler
{
    public int $calls = 0;
    private $result;

    public function __construct($result)
    {
        $this->result = $result;
    }

    public function handle(Context $context)
    {
        ++$this->calls;

        return $this->result;
    }
}

final class NullMessageBus implements MessageBusInterface
{
    public function dispatch(object $message, ?PublishOptions $options = null, ?Envelope $causation = null)
    {
        return null;
    }

    public function dispatchEnvelope(Envelope $envelope)
    {
        return null;
    }
}
