<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Handler;

use Closure;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\Context;

/**
 * @template TResult
 * @template TMessage of Message<TResult>
 * @implements Handler<TResult, TMessage>
 */
class CallableHandler implements Handler
{
    /**
     * @param Closure(TMessage, Context<TResult, TMessage>): TResult $handler
     */
    public function __construct(
        private readonly Closure $handler,
    ) {
    }

    public function handle(Context $context): mixed
    {
        return ($this->handler)($context->envelope->message, $context);
    }
}
