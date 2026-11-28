<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\HandlerServiceResolver;
use Wolfcharaa\MessageBus\Registry\HandlerBinding;

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
 * Resolves the complete middleware chain for one exact handler binding.
 *
 * The optional policy is present only for durable worker attempts. Resolution
 * uses the supplied application scope and must not translate its failures.
 *
 * @api spi
 */
interface HandlerMiddlewareResolver
{
    /** @return list<HandlerMiddleware<mixed>> */
    public function for(
        HandlerBinding $binding,
        HandlerServiceResolver $services,
        ?DeliveryPolicy $policy = null,
    ): array;
}

/**
 * Composes global, delivery-policy, and handler-local middleware in that order.
 *
 * Class references from policies and #[Handler] are resolved through the same
 * application service scope as handlers. Container failures escape unchanged.
 *
 * @api application
 */
final readonly class HandlerMiddlewares implements HandlerMiddlewareResolver
{
    /** @param list<HandlerMiddleware<mixed>> $global */
    public function __construct(private array $global = [])
    {
        foreach ($this->global as $index => $middleware) {
            if (!$middleware instanceof HandlerMiddleware) {
                throw new \InvalidArgumentException(
                    'Global handler middleware[' . $index . '] must implement '
                    . HandlerMiddleware::class . '; got ' . get_debug_type($middleware) . '.',
                );
            }
        }
    }

    public function for(
        HandlerBinding $binding,
        HandlerServiceResolver $services,
        ?DeliveryPolicy $policy = null,
    ): array {
        $resolved = $this->global;
        $policyMiddleware = $policy instanceof DeliveryPolicy ? $policy->middleware : [];
        foreach ([...$policyMiddleware, ...$binding->middleware] as $middlewareClass) {
            $middleware = $services->get($middlewareClass);
            if (!$middleware instanceof HandlerMiddleware) {
                throw new \LogicException(
                    'Application service resolver returned ' . get_debug_type($middleware)
                    . ' for handler middleware ' . $middlewareClass . ' on binding ' . $binding->id
                    . '; expected an instance of ' . HandlerMiddleware::class . '.',
                );
            }
            $resolved[] = $middleware;
        }

        return $resolved;
    }
}
