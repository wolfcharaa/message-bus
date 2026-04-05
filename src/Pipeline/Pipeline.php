<?php

declare(strict_types=1);

namespace App\MessageBus\Pipeline;

use App\MessageBus\Handler\Handler;
use App\MessageBus\Message\Message;
use App\MessageBus\Message\Context;
use App\MessageBus\Middleware\Middleware;

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
