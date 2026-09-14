<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyPolicyMissing;

final readonly class ResolvedIdempotencyPolicyRegistry implements ResolvedIdempotencyPolicyRegistryInterface
{
    /** @var array<string, ResolvedIdempotencyPolicy> */
    private array $policies;

    public function __construct(ResolvedIdempotencyPolicy ...$policies)
    {
        $indexed = [];
        foreach ($policies as $policy) {
            if (isset($indexed[$policy->bindingId])) {
                throw new \InvalidArgumentException(\sprintf('Idempotency policy for binding `%s` is already registered.', $policy->bindingId));
            }

            $indexed[$policy->bindingId] = $policy;
        }

        $this->policies = $indexed;
    }

    public function hasForBinding(string $bindingId): bool
    {
        return $this->has($bindingId);
    }

    public function ownerForBinding(string $bindingId): ?\Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner
    {
        return $this->policies[$bindingId]->owner ?? null;
    }

    public function has(string $bindingId): bool
    {
        return isset($this->policies[$bindingId]);
    }

    public function get(string $bindingId): ?ResolvedIdempotencyPolicy
    {
        return $this->policies[$bindingId] ?? null;
    }

    public function require(string $bindingId): ResolvedIdempotencyPolicy
    {
        return $this->policies[$bindingId] ?? throw IdempotencyPolicyMissing::forBinding($bindingId);
    }

    public function all(): iterable
    {
        return $this->policies;
    }

    /** @return array<string, array<string, mixed>> */
    public function toArray(): array
    {
        return \array_map(
            static fn (ResolvedIdempotencyPolicy $policy): array => $policy->toArray(),
            $this->policies,
        );
    }
}
