<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

final class Pipeline implements PipelineInterface
{
    /** @param list<class-string> $middleware */
    public function __construct(
        private readonly HandlerBindingDefinition $binding,
        private readonly MessageContextInterface $context,
        private readonly CallableInvokerInterface $invoker,
        private array $middleware,
    ) {
    }

    public function continue(): mixed
    {
        $middleware = \array_shift($this->middleware);

        if ($middleware !== null) {
            return $this->invoker->invoke($middleware, '__invoke', [$this->context, $this]);
        }

        return $this->invoker->invoke(
            $this->binding->action,
            $this->binding->method,
            [$this->context->envelope()->message, $this->context],
        );
    }
}
