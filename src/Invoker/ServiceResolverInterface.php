<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Invoker;

interface ServiceResolverInterface
{
    public function get(string $id): object;
}
