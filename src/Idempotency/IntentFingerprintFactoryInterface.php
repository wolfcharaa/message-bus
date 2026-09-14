<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

interface IntentFingerprintFactoryInterface
{
    public function fingerprint(object $message): IntentFingerprint;
}
