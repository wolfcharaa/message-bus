<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Message\Query;

interface MessageBusFanOutInterface extends MessageBusInterface
{
    /**
     * @template TResult
     * @param Query<TResult>|Command|object $message
     * @return FanOutResult<TResult>
     */
    public function dispatchWithFanOut(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): FanOutResult;
}
