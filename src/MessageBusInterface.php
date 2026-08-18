<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use BackedEnum;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;

interface MessageBusInterface
{
    /**
     * @template TResult
     * @param \Wolfcharaa\MessageBus\Message\Command<TResult>|\Wolfcharaa\MessageBus\Message\Query<TResult>|object $message
     * @return TResult
     */
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed;

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface;

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): void;

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface;

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed;

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed;
}
