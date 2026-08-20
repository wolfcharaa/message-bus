<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;

final class DefaultMessageContext implements MessageContextInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly Envelope $envelope,
    ) {
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        return $this->messageBus->dispatch($message, $options, $this->envelope);
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        return $this->messageBus->dispatchAll($message, $options, $this->envelope);
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        return $this->messageBus->publish($message, $options, $this->envelope);
    }
}
