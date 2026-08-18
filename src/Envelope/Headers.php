<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use BackedEnum;
use InvalidArgumentException;
use JsonSerializable;

final class Headers implements JsonSerializable
{
    /** @var array<string, mixed> */
    private readonly array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                throw new InvalidArgumentException('Header key must be a string.');
            }

            self::assertPortableValue($value);
        }

        $this->values = $values;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function get(string|BackedEnum $key): mixed
    {
        return $this->values[self::normalizeKey($key)] ?? null;
    }

    public function with(string|BackedEnum $key, mixed $value): self
    {
        self::assertPortableValue($value);

        $values = $this->values;
        $values[self::normalizeKey($key)] = $value;

        return new self($values);
    }

    public function merge(self $headers): self
    {
        return new self(\array_merge($this->values, $headers->values));
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }

    public function jsonSerialize(): array
    {
        return $this->values;
    }

    private static function normalizeKey(string|BackedEnum $key): string
    {
        return $key instanceof BackedEnum ? (string) $key->value : $key;
    }

    private static function assertPortableValue(mixed $value): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertPortableValue($item);
            }

            return;
        }

        throw new InvalidArgumentException('Header value must be scalar, array or null.');
    }
}
