<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

interface CallableInvokerInterface
{
    /**
     * @param class-string|object $service
     * @param array<int, mixed> $arguments
     */
    public function invoke(string|object $service, string $method, array $arguments): mixed;
}
