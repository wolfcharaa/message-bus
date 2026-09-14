<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use DateTimeImmutable;

final readonly class IdempotencyExecution
{
    public function __construct(
        public string $bindingId,
        public string $flow,
        public string $handlerKind,
        public string $messageClass,
        public ?string $messageAlias,
        public string $messageId,
        public string $correlationId,
        public ?string $causationId,
        public DateTimeImmutable $createdAt,
    ) {
    }
}
