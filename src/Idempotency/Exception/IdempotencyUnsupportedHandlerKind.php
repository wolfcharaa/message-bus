<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class IdempotencyUnsupportedHandlerKind extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forKind(string $bindingId, string $kind): self
    {
        return new self(\sprintf('Idempotency does not support `%s` handler binding `%s`.', $kind, $bindingId));
    }
}
