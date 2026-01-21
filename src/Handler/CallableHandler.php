<?php

declare(strict_types=1);

namespace App\MessageBus\Handler;

use Closure;
use App\MessageBus\Message\Message;
use App\MessageBus\Message\Context;

/**
 * @template TResult
 * @template TMessage of Message<TResult>
 * @implements Handler<TResult, TMessage>
 */
class CallableHandler implements Handler
{
    /**
     * @var Closure(TMessage, Context<TResult, TMessage>): TResult $handler
     */
    private Closure $handler;

    public function __construct(
        Closure $handler
    ) {
        $this->handler = $handler;
    }

    public function handle(Context $context)
    {
        return ($this->handler)($context->envelope->message, $context);
    }
}
