<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Handler;

use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Middleware\Middleware;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;

/**
 * @template TResult
 * @template TMessage of Message<TResult>
 * @implements Handler<TResult, TMessage>
 */
class HandlerWithMiddleware implements Handler
{
    /**
     * @param Handler<TResult, TMessage> $handler
     * @param non-empty-list<Middleware> $middleware
     */
    public function __construct(
        private readonly Handler $handler,
        private readonly array $middleware,
    ) {
    }

    public function handle(Context $context): mixed
    {
        return (new Pipeline($this->handler, $context, $this->middleware))->continue();
    }
}
