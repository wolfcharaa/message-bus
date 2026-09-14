<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ResolvedServiceReference;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;

final readonly class ResolvedIdempotencyPolicy
{
    /**
     * @param ResolvedServiceReference<IdempotencyKeyExtractorInterface> $keyExtractor
     * @param ResolvedServiceReference<IntentFingerprintFactoryInterface> $fingerprintFactory
     * @param ResolvedServiceReference<IdempotencyStoreInterface> $store
     * @param ResolvedServiceReference<IdempotencyEffectReferenceFactoryInterface>|null $effectReferenceFactory
     */
    public function __construct(
        public string $bindingId,
        public RegistryOwner $owner,
        public RegistrySource $source,
        public ResolvedServiceReference $keyExtractor,
        public ResolvedServiceReference $fingerprintFactory,
        public ResolvedServiceReference $store,
        public ?ResolvedServiceReference $effectReferenceFactory = null,
        public ?int $retentionSeconds = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return \array_filter([
            'bindingId' => $this->bindingId,
            'owner' => $this->owner->toArray(),
            'source' => $this->source->toArray(),
            'keyExtractor' => $this->keyExtractor->toArray(),
            'fingerprintFactory' => $this->fingerprintFactory->toArray(),
            'store' => $this->store->toArray(),
            'effectReferenceFactory' => $this->effectReferenceFactory?->toArray(),
            'retentionSeconds' => $this->retentionSeconds,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
