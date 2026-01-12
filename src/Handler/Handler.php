<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Handler;

use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Message\Message;

/**
 * @template TResult = mixed
 * @template TMessage of Message<TResult> = Message<mixed>
 */
interface Handler
{
    /**
     * @param Context<TResult, TMessage> $context
     * @return TResult
     */
    public function handle(Context $context): mixed;
}
