<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Clock\WallClock;
use Wolfcharaa\MessageBus\HandlerRegistry\HandlerRegistryInterface;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\MessageId\MessageIdGenerator;
use Wolfcharaa\MessageBus\Message\MessageId\RandomMessageIdGenerator;

final class MessageBus implements MessageBusInterface
{
    private HandlerRegistryInterface $handlerRegistry;
    private MessageIdGenerator $messageIdGenerator;
    private ClockInterface $clock;

    public function __construct(
        HandlerRegistryInterface $handlerRegistry,
        ?MessageIdGenerator $messageIdGenerator,
        ?ClockInterface $clock
    ) {
        $this->handlerRegistry = $handlerRegistry;
        $this->messageIdGenerator = $messageIdGenerator ?? new RandomMessageIdGenerator();
        $this->clock = $clock ?? new WallClock();
    }

    /**
     * @template TResult
     * @param Message<TResult>|object $message
     * @param ?PublishOptions $options
     * @param Envelope|null $causation
     * @return TResult
     */
    public function dispatch(
        object $message,
        ?PublishOptions $options = null,
        ?Envelope $causation = null
    ) {
        $options ??= new PublishOptions();
        $messageId = $options->messageId ?? $this->messageIdGenerator->generateMessageId();
        $envelope = new Envelope(
            $message,
            $messageId,
            $causation !== null ? $causation->messageId : null,
            $causation !== null ? $causation->correlationId : $messageId,
            $this->clock->now(),
            $options->header,
        );
        $context = new Context(
            $this,
            $envelope,
        );

        return $this->handlerRegistry->get(\get_class($message))->handle($context);
    }
}
