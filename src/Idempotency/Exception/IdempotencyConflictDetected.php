<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyExecution;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;

final class IdempotencyConflictDetected extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forExecution(IdempotencyExecution $execution, IdempotencyKey $key): self
    {
        return new self(\sprintf(
            'Idempotency conflict for binding `%s` and key digest `%s`.',
            $execution->bindingId,
            $key->digest,
        ));
    }
}
