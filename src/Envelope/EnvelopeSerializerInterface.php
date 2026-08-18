<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

interface EnvelopeSerializerInterface
{
    public function serialize(Envelope $envelope): SerializedEnvelope;

    public function deserialize(SerializedEnvelope $envelope): Envelope;
}
