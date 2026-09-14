<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use DateTimeImmutable;

final readonly class IdempotencyCompletion
{
    public function __construct(
        public DateTimeImmutable $completedAt,
        public ?IdempotencyEffectReference $effectReference = null,
    ) {
    }
}
