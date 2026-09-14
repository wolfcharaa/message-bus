<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Bootstrap\ServiceReference;

use InvalidArgumentException;

/**
 * @template T of object
 */
final readonly class ResolvedServiceReference
{
    /**
     * @param ServiceReference<T> $reference
     * @param T $service
     */
    public function __construct(
        public ServiceReference $reference,
        private object $service,
    ) {
        if (!$this->service instanceof $this->reference->expectedType) {
            throw new InvalidArgumentException(\sprintf(
                'Resolved service `%s` must implement `%s`, got `%s`.',
                $this->reference->id,
                $this->reference->expectedType,
                $this->service::class,
            ));
        }
    }

    /** @return T */
    public function service(): object
    {
        return $this->service;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            ...$this->reference->toArray(),
            'resolvedClass' => $this->service::class,
        ];
    }
}
