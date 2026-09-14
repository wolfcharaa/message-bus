<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

interface IdempotencyEffectReferenceFactoryInterface
{
    public function effectReference(object $message, IdempotencyExecution $execution): ?IdempotencyEffectReference;
}
