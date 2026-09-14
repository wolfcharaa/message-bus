<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency\Exception;

use RuntimeException;

abstract class IdempotencyException extends RuntimeException
{
}
