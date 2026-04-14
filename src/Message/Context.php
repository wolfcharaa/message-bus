<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message;

use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;

/**
 * @template TResult = mixed
 * @template TMessage of Message<TResult>|object = Message<mixed>
 */
final class Context
{
    private MessageBusInterface $messageBus;
    /**
     * @var Envelope<TResult, TMessage> $envelope
     */
    public Envelope $envelope;

    public function __construct(
        MessageBusInterface $messageBus,
        Envelope $envelope
    ) {
        $this->messageBus = $messageBus;
        $this->envelope = $envelope;
    }

    /**
     * @template TDispatchResult
     * @param Message<TDispatchResult>|object $message
     * @return TDispatchResult
     */
    public function dispatch(object $message, ?PublishOptions $options = null)
    {
        return $this->messageBus->dispatch($message, $options ?? new PublishOptions(), $this->envelope);
    }
}
