<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

interface SerializedEnvelopeNormalizerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(SerializedEnvelope $envelope): array;

    /**
     * @param array<string, mixed> $data
     */
    public function fromArray(array $data): SerializedEnvelope;
}
