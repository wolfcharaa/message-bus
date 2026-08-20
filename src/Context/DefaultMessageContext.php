<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Exception\MessageCancellationRequested;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;

final class DefaultMessageContext implements MessageContextInterface, CancellableMessageContextInterface, HeartbeatAwareMessageContextInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly Envelope $envelope,
        private readonly ?WorkerRuntimeControlInterface $workerRuntimeControl = null,
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

    public function heartbeat(): void
    {
        $this->workerRuntimeControl?->heartbeat();
    }

    public function isCancellationRequested(): bool
    {
        return $this->workerRuntimeControl?->isCancellationRequested() ?? false;
    }

    public function throwIfCancellationRequested(): void
    {
        if ($this->isCancellationRequested()) {
            throw new MessageCancellationRequested('Message cancellation was requested.');
        }
    }
}
