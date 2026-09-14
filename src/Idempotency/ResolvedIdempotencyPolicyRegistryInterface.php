<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Flow\Contract\OwnedBindingPolicyRegistryInterface;

interface ResolvedIdempotencyPolicyRegistryInterface extends OwnedBindingPolicyRegistryInterface
{
    public function has(string $bindingId): bool;

    public function get(string $bindingId): ?ResolvedIdempotencyPolicy;

    public function require(string $bindingId): ResolvedIdempotencyPolicy;

    /** @return iterable<string, ResolvedIdempotencyPolicy> */
    public function all(): iterable;
}
