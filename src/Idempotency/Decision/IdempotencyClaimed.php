<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Decision;

use Wolfcharaa\MessageBus\Idempotency\IdempotencyClaim;

final readonly class IdempotencyClaimed implements IdempotencyDecisionInterface
{
    public function __construct(public IdempotencyClaim $claim)
    {
    }
}
