<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

use ReflectionMethod;
use RuntimeException;

final class ReflectionCallableInvoker implements CallableInvokerInterface
{
    public function __construct(private readonly ServiceResolverInterface $resolver = new InstantiatingServiceResolver())
    {
    }

    public function invoke(string|object $service, string $method, array $arguments): mixed
    {
        $instance = \is_string($service) ? $this->resolver->get($service) : $service;

        if (!\method_exists($instance, $method)) {
            throw new RuntimeException(\sprintf(
                'Callable target `%s::%s` does not exist.',
                $instance::class,
                $method,
            ));
        }

        $reflection = new ReflectionMethod($instance, $method);

        return $reflection->invokeArgs($instance, $arguments);
    }
}
