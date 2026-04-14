<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Wolfcharaa\MessageBus\Message\Message;

interface MessageBusInterface
{
    /**
     * @template TResult
     * @param Message<TResult>|object $message
     * @return TResult
     */
    public function dispatch(
        object $message,
        ?PublishOptions $options = null,
        ?Envelope $causation = null
    );
}
