<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Message\Message;
use Exception;
use Throwable;

class HandlerMessageExists extends Exception
{
    /**
     * @param class-string<Message> $messageClass
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(
        string $messageClass,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct(sprintf(
            'Handler message `%s` already exists',
            $messageClass
        ), $code, $previous);
    }
}
