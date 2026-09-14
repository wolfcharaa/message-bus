<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Decision;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyEffectReference;

final readonly class IdempotencyCompletedSame implements IdempotencyDecisionInterface
{
    public function __construct(
        public DateTimeImmutable $completedAt,
        public ?IdempotencyEffectReference $effectReference = null,
    ) {
    }
}
