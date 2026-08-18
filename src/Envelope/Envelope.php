<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use DateTimeImmutable;

final class Envelope
{
    public function __construct(
        public readonly object $message,
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly ?string $causationId,
        public readonly string $flow,
        public readonly ?string $bindingId,
        public readonly DateTimeImmutable $createdAt,
        public readonly Headers $headers = new Headers(),
    ) {
    }

    public function withFlowBinding(string $flow, ?string $bindingId): self
    {
        return new self(
            $this->message,
            $this->messageId,
            $this->correlationId,
            $this->causationId,
            $flow,
            $bindingId,
            $this->createdAt,
            $this->headers,
        );
    }
}
