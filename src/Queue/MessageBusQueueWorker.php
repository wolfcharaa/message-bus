<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\MessageBusInterface;

final class MessageBusQueueWorker implements QueueWorkerInterface
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly EnvelopeSerializerInterface $serializer,
    ) {
    }

    public function handle(SerializedEnvelope $envelope): mixed
    {
        return $this->messageBus->dispatchEnvelopeToBinding(
            $this->serializer->deserialize($envelope),
        );
    }
}
