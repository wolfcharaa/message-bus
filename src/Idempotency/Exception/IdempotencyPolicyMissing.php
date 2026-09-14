<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class IdempotencyPolicyMissing extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forBinding(?string $bindingId): self
    {
        return new self(\sprintf('Idempotency policy is required for binding `%s`.', $bindingId ?? ''));
    }
}
