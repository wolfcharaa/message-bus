<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReference;

final readonly class IdempotencyPolicyDescriptor
{
    /**
     * @param ServiceReference<IdempotencyKeyExtractorInterface> $keyExtractor
     * @param ServiceReference<IntentFingerprintFactoryInterface> $fingerprintFactory
     * @param ServiceReference<IdempotencyStoreInterface> $store
     * @param ServiceReference<IdempotencyEffectReferenceFactoryInterface>|null $effectReferenceFactory
     */
    public function __construct(
        public string $bindingId,
        public ServiceReference $keyExtractor,
        public ServiceReference $fingerprintFactory,
        public ServiceReference $store,
        public ?ServiceReference $effectReferenceFactory = null,
        public ?int $retentionSeconds = null,
    ) {
        if (\trim($this->bindingId) === '') {
            throw new \InvalidArgumentException('Idempotency policy bindingId must be non-empty.');
        }

        if ($this->retentionSeconds !== null && $this->retentionSeconds <= 0) {
            throw new \InvalidArgumentException('Idempotency retentionSeconds must be positive.');
        }

        $this->assertReference($this->keyExtractor, IdempotencyKeyExtractorInterface::class, 'keyExtractor');
        $this->assertReference($this->fingerprintFactory, IntentFingerprintFactoryInterface::class, 'fingerprintFactory');
        $this->assertReference($this->store, IdempotencyStoreInterface::class, 'store');
        if ($this->effectReferenceFactory !== null) {
            $this->assertReference($this->effectReferenceFactory, IdempotencyEffectReferenceFactoryInterface::class, 'effectReferenceFactory');
        }
    }

    public static function forBinding(
        string $bindingId,
        ServiceReference $keyExtractor,
        ServiceReference $fingerprintFactory,
        ServiceReference $store,
        ?ServiceReference $effectReferenceFactory = null,
        ?int $retentionSeconds = null,
    ): self {
        return new self($bindingId, $keyExtractor, $fingerprintFactory, $store, $effectReferenceFactory, $retentionSeconds);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return \array_filter([
            'bindingId' => $this->bindingId,
            'keyExtractor' => $this->keyExtractor->toArray(),
            'fingerprintFactory' => $this->fingerprintFactory->toArray(),
            'store' => $this->store->toArray(),
            'effectReferenceFactory' => $this->effectReferenceFactory?->toArray(),
            'retentionSeconds' => $this->retentionSeconds,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function assertReference(ServiceReference $reference, string $expectedType, string $field): void
    {
        if (!\is_a($reference->expectedType, $expectedType, true)) {
            throw new \InvalidArgumentException(\sprintf(
                'Idempotency policy %s reference must expect `%s`, got `%s`.',
                $field,
                $expectedType,
                $reference->expectedType,
            ));
        }
    }
}
