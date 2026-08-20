<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use Wolfcharaa\MessageBus\Serialization\MessageSerializerInterface;

final class DefaultEnvelopeSerializer implements EnvelopeSerializerInterface
{
    public function __construct(private readonly MessageSerializerInterface $messageSerializer)
    {
    }

    public function serialize(Envelope $envelope): SerializedEnvelope
    {
        return new SerializedEnvelope(
            $this->messageSerializer->serialize($envelope->message),
            $envelope->headers->all(),
            $envelope->messageId,
            $envelope->causationId,
            $envelope->correlationId,
            $envelope->flow,
            $envelope->bindingId,
            $envelope->createdAt,
            SerializedEnvelope::SCHEMA_VERSION,
        );
    }

    public function deserialize(SerializedEnvelope $envelope): Envelope
    {
        return new Envelope(
            $this->messageSerializer->deserialize($envelope->message),
            $envelope->messageId,
            $envelope->correlationId,
            $envelope->causationId,
            $envelope->flow,
            $envelope->bindingId,
            $envelope->createdAt,
            new Headers($envelope->headers),
        );
    }
}
