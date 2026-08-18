<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Message\MessageIdGenerator;
use Wolfcharaa\MessageBus\PublishOptions;

final class EnvelopeFactory
{
    public function __construct(
        private readonly MessageIdGenerator $messageIdGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public function create(
        object $message,
        string $flow,
        ?string $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): Envelope {
        $messageId = $options->messageId ?? $this->messageIdGenerator->generate();

        return new Envelope(
            $message,
            $messageId,
            $causation?->correlationId ?? $messageId,
            $causation?->messageId,
            $flow,
            $bindingId,
            $this->clock->now(),
            $options->headers,
        );
    }
}
