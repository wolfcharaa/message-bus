<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Bootstrap\ServiceReference;

use InvalidArgumentException;

/**
 * @template T of object
 */
final readonly class ServiceReference
{
    /**
     * @param class-string<T> $expectedType
     */
    public function __construct(
        public string $id,
        public string $expectedType,
        public ?string $role = null,
    ) {
        if (\trim($this->id) === '') {
            throw new InvalidArgumentException('Service reference id must be non-empty.');
        }

        if (!\interface_exists($this->expectedType) && !\class_exists($this->expectedType)) {
            throw new InvalidArgumentException(\sprintf('Service reference expected type `%s` does not exist.', $this->expectedType));
        }
    }

    /**
     * @template TService of object
     * @param class-string<TService> $expectedType
     * @return self<TService>
     */
    public static function service(string $id, string $expectedType, ?string $role = null): self
    {
        return new self($id, $expectedType, $role);
    }

    /**
     * @template TService of object
     * @param class-string<TService> $class
     * @return self<TService>
     */
    public static function class(string $class, ?string $role = null): self
    {
        return new self($class, $class, $role);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return \array_filter([
            'id' => $this->id,
            'expectedType' => $this->expectedType,
            'role' => $this->role,
        ], static fn (?string $value): bool => $value !== null);
    }
}
