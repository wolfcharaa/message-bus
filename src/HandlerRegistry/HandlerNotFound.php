<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use LogicException;
use Throwable;

class HandlerNotFound extends LogicException
{
    public function __construct(string $messageClass, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct(
            sprintf('No handler for non-event message `%s`', $messageClass),
            $code,
            $previous
        );
    }
}
