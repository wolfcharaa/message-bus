<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

interface CachePolicyRegistryInterface
{
    public function forBinding(string $bindingId): ?CachePolicy;
}
