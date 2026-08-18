<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

final class InstantiatingServiceResolver implements ServiceResolverInterface
{
    public function get(string $id): object
    {
        return new $id();
    }
}
