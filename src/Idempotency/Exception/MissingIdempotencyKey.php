<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class MissingIdempotencyKey extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
    public static function forMessage(object $message): self
    {
        return new self(\sprintf('Idempotency key is required for message `%s`.', $message::class));
    }
}
