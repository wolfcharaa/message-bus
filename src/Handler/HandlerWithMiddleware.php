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
     * @var Handler<TResult, TMessage> $handler
     */
    private Handler $handler;
    /**
     * @var non-empty-list<Middleware> $middleware
     */
    private array $middleware;

    public function __construct(
        Handler $handler,
        array $middleware
    ) {
        $this->handler = $handler;
        $this->middleware = $middleware;
    }

    public function handle(Context $context)
    {
        return (new Pipeline($this->handler, $context, $this->middleware))->continue();
    }
}
