<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Decision;

use DateTimeImmutable;

final readonly class IdempotencyInProgress implements IdempotencyDecisionInterface
{
    public function __construct(public ?DateTimeImmutable $claimedAt = null)
    {
    }
}
