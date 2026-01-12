<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\MessageBus;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Clock\WallClock;
use Wolfcharaa\MessageBus\HandlerRegistry\HandlerRegistryInterface;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\MessageId\MessageIdGenerator;
use Wolfcharaa\MessageBus\Message\MessageId\RandomMessageIdGenerator;

final class MessageBus implements MessageBusInterface
{
    public function __construct(
        private readonly HandlerRegistryInterface $handlerRegistry,
        private readonly MessageIdGenerator $messageIdGenerator = new RandomMessageIdGenerator(),
        private readonly ClockInterface $clock = new WallClock(),
    ) {
    }

    /**
     * @template TResult
     * @param Message<TResult> $message
     * @param PublishOptions $options
     * @param Envelope|null $causation
     * @return TResult
     */
    public function dispatch(
        Message $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null
    ): mixed {
        $messageId = $options->messageId ?? $this->messageIdGenerator->generateMessageId();
        $envelope = new Envelope(
            message: $message,
            messageId: $messageId,
            causationId: $causation?->messageId,
            correlationId: $causation->correlationId ?? $messageId,
            timestamp: $this->clock->now(),
            headers: $options->headers ?? [],
        );
        $context = new Context(
            messageBus: $this,
            envelope: $envelope,
        );

        return $this->handlerRegistry->get($message::class)->handle($context);
    }
}
