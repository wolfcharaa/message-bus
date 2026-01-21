<?php

declare(strict_types=1);

namespace App\MessageBus;

use App\MessageBus\Message\Message;

interface MessageBusInterface
{
    /**
     * @template TResult
     * @param Message<TResult> $message
     * @return TResult
     */
    public function dispatch(
        Message $message,
        ?PublishOptions $options = null,
        ?Envelope $causation = null
    );
}
