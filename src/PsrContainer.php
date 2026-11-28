<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter\Psr\Container;

use Psr\Container\ContainerInterface;
use Wolfcharaa\MessageBus\HandlerServiceResolver;

/**
 * Resolves compiled handlers from a PSR-11 container without translating its failures.
 *
 * Exceptions thrown by `ContainerInterface::get()` and `has()` escape unchanged,
 * preserving the container's own autowiring diagnostics. The adapter adds a
 * library error only when a successful `get()` returns a value incompatible
 * with the compiled handler class.
 *
 * @api adapter
 */
final readonly class PsrContainerHandlerServiceResolver implements HandlerServiceResolver
{
    public function __construct(private ContainerInterface $container) {}

    /** @param class-string $class */
    public function get(string $class): object
    {
        $service = $this->container->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException(
                'PSR-11 container returned ' . get_debug_type($service)
                . ' for handler ' . $class . '; expected an instance of ' . $class . '.',
            );
        }

        return $service;
    }

    /** @param class-string $class */
    public function has(string $class): bool
    {
        return $this->container->has($class);
    }
}
