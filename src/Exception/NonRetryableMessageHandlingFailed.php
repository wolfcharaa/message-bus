<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Exception;

use RuntimeException;

final class NonRetryableMessageHandlingFailed extends RuntimeException implements NonRetryableMessageExceptionInterface
{
}
