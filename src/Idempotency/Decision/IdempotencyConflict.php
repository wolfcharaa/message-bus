<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Decision;

use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;

final readonly class IdempotencyConflict implements IdempotencyDecisionInterface
{
    public function __construct(public ?IntentFingerprint $existingFingerprint = null)
    {
    }
}
