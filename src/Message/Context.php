<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message;

use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;

/**
 * @template TResult = mixed
 * @template TMessage of Message<TResult> = Message<mixed>
 */
final class Context
{
    /**
     * @param Envelope<TResult, TMessage> $envelope
     */
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        public readonly Envelope $envelope,
    ) {
    }

    /**
     * @template TDispatchResult
     * @param Message<TDispatchResult> $message
     * @return TDispatchResult
     */
    public function dispatch(Message $message, ?PublishOptions $options = new PublishOptions()): mixed
    {
        return $this->messageBus->dispatch($message, $options, $this->envelope);
    }
}
