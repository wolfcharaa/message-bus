<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

use Psr\Container\ContainerInterface;
use RuntimeException;

final class PsrContainerServiceResolver implements ServiceResolverInterface
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function get(string $id): object
    {
        $service = $this->container->get($id);

        if (!\is_object($service)) {
            throw new RuntimeException(\sprintf('Service `%s` must resolve to object.', $id));
        }

        return $service;
    }
}
