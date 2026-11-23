<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Wolfcharaa\MessageBus\Context\MessageContext;

/**
 * One handler-attempt middleware extension point.
 *
 * @template TResult
 * @api spi
 */
interface HandlerMiddleware
{
    /**
     * @param NextHandler<TResult> $next
     * @return TResult
     */
    public function process(object $message, MessageContext $context, NextHandler $next): mixed;
}

/**
 * One-shot continuation. A middleware may call it once or short-circuit.
 *
 * @template-covariant TResult
 * @api spi
 */
interface NextHandler
{
    /** @return TResult */
    public function handle(): mixed;
}

/**
 * Resolves the complete middleware chain for each handler invocation.
 *
 * @api spi
 */
interface HandlerMiddlewareResolver
{
    /** @return list<HandlerMiddleware<mixed>> */
    public function all(): array;
}

/**
 * Exact fixed middleware list for applications that do not need scoped resolution.
 *
 * @api application
 */
final readonly class HandlerMiddlewares implements HandlerMiddlewareResolver
{
    /** @param list<HandlerMiddleware<mixed>> $middleware */
    public function __construct(private array $middleware = []) {}

    public function all(): array
    {
        return $this->middleware;
    }
}
