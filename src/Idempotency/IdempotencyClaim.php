<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

final readonly class IdempotencyClaim
{
    public function __construct(
        public string $bindingId,
        public IdempotencyKey $key,
        public IntentFingerprint $fingerprint,
        public string $claimId,
    ) {
    }
}
