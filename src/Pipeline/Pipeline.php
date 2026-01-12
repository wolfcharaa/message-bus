<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Pipeline;

use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Middleware\Middleware;

/**
 * @template TResult
 * @template TMessage of Message<TResult>
 */
final class Pipeline
{
    /**
     * @param array<non-negative-int, Middleware> $middleware
     * @param Handler<TResult, TMessage> $handler
     * @param Context<TResult, TMessage> $context
     */
    public function __construct(
        private readonly Handler $handler,
        private readonly Context $context,
        private array $middleware,
    ) {
    }

    /**
     * @return TResult
     */
    public function continue(): mixed
    {
        $middleware = array_shift($this->middleware);

        if ($middleware !== null) {
            return $middleware->handle($this->context, $this);
        }

        return $this->handler->handle($this->context);
    }
}
