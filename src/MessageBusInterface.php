<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Wolfcharaa\MessageBus\Message\Message;

interface MessageBusInterface
{
    /**
     * @template TResult
     * @param Message<TResult> $message
     * @return TResult
     */
    public function dispatch(
        Message $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null
    ): mixed;
}
