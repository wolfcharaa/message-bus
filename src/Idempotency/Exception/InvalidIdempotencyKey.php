<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;

final class InvalidIdempotencyKey extends IdempotencyException implements NonRetryableMessageExceptionInterface
{
}
