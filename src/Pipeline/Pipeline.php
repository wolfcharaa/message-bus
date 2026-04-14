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
     * @var Handler<TResult, TMessage> $handler
     */
    private Handler $handler;
    /**
     * @var Context<TResult, TMessage> $context
     */
    private Context $context;
    /**
     * @var array<non-negative-int, Middleware> $middleware
     */
    private array $middleware;

    public function __construct(
        Handler $handler,
        Context $context,
        array $middleware
    ) {
        $this->handler = $handler;
        $this->context = $context;
        $this->middleware = $middleware;
    }


    /**
     * @return TResult
     */
    public function continue()
    {
        $middleware = \array_shift($this->middleware);

        if ($middleware !== null) {
            return $middleware->handle($this->context, $this);
        }

        return $this->handler->handle($this->context);
    }
}
