<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionMethod;
use RuntimeException;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;

final class ReflectionCallableInvoker implements CallableInvokerInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function invoke(string|object $service, string $method, array $arguments): mixed
    {
        $instance = \is_string($service) ? $this->service($service) : $service;

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

    private function service(string $id): object
    {
        try {
            if (!$this->container->has($id)) {
                throw new ContainerServiceNotFound([$id], 'callable target', 'object');
            }

            $service = $this->container->get($id);
        } catch (NotFoundExceptionInterface $e) {
            throw new ContainerServiceNotFound([$id], 'callable target', 'object', previous: $e);
        } catch (ContainerExceptionInterface $e) {
            throw new ContainerServiceInvalid([$id], 'callable target', 'object', 'container error', previous: $e);
        }

        if (!\is_object($service)) {
            throw new ContainerServiceInvalid([$id], 'callable target', 'object', \get_debug_type($service));
        }

        return $service;
    }
}
