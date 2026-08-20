<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

final class ArrayCachePolicyRegistry implements CachePolicyRegistryInterface
{
    /** @param array<string, CachePolicy> $policies */
    public function __construct(private readonly array $policies)
    {
    }

    public function forBinding(string $bindingId): ?CachePolicy
    {
        return $this->policies[$bindingId] ?? null;
    }
}
