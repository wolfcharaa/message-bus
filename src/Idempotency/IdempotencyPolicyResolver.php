<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReferenceResolverInterface;

final readonly class IdempotencyPolicyResolver
{
    public function __construct(private ServiceReferenceResolverInterface $services)
    {
    }

    /**
     * @param iterable<IdempotencyPolicyProviderInterface> $providers
     */
    public function resolve(iterable $providers): ResolvedIdempotencyPolicyRegistry
    {
        $resolved = [];
        $sources = [];

        foreach ($providers as $provider) {
            $owner = $provider->owner();
            $source = $provider->source();

            foreach ($provider->policies() as $descriptor) {
                if (!$descriptor instanceof IdempotencyPolicyDescriptor) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Idempotency policy provider `%s` must return only `%s` instances.',
                        $provider::class,
                        IdempotencyPolicyDescriptor::class,
                    ));
                }

                if (isset($resolved[$descriptor->bindingId])) {
                    throw new \InvalidArgumentException(\sprintf(
                        'Duplicate idempotency policy for binding `%s` from `%s` and `%s`.',
                        $descriptor->bindingId,
                        $sources[$descriptor->bindingId],
                        $source->name,
                    ));
                }

                $resolved[$descriptor->bindingId] = new ResolvedIdempotencyPolicy(
                    $descriptor->bindingId,
                    $owner,
                    $source,
                    $this->services->resolve($descriptor->keyExtractor),
                    $this->services->resolve($descriptor->fingerprintFactory),
                    $this->services->resolve($descriptor->store),
                    $descriptor->effectReferenceFactory !== null
                        ? $this->services->resolve($descriptor->effectReferenceFactory)
                        : null,
                    $descriptor->retentionSeconds,
                );
                $sources[$descriptor->bindingId] = $source->name;
            }
        }

        return new ResolvedIdempotencyPolicyRegistry(...\array_values($resolved));
    }
}
