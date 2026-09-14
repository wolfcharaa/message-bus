<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyDecisionInterface;

interface IdempotencyStoreInterface
{
    public function claim(
        IdempotencyExecution $execution,
        IdempotencyKey $key,
        IntentFingerprint $fingerprint,
    ): IdempotencyDecisionInterface;

    public function complete(IdempotencyClaim $claim, IdempotencyCompletion $completion): void;
}
