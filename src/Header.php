<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

class Header implements \JsonSerializable
{
    /**
     * @var array<class-string, \JsonSerializable> $values
     */
    private array $values;

    public function __construct(\JsonSerializable ...$values)
    {
        $this->values = \array_reduce(
            $values,
            static function (array $carry, object $value): array {
                $carry[\get_class($value)] = $value;

                return $carry;
            },
            []
        );
    }

    /**
     * @template T of ?object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): ?object
    {
        /** @phpstan-ignore return.type */
        return $this->values[$class] ?? null;
    }

    public function with(\JsonSerializable $value): self
    {
        $clone = clone $this;
        $clone->values = array_reduce(
            $clone->values,
            static function (array $carry, object $value): array {
                $carry[\get_class($value)] = $value;

                return $carry;
            },
            [\get_class($value) => $value]
        );

        return $clone;
    }

    public function jsonSerialize(): array
    {
        return \array_reduce(
            $this->values,
            static function (array $items, \JsonSerializable $object): array {
                $items[\lcfirst(\basename(\str_replace('\\', '/', \get_class($object))))]
                    = $object->jsonSerialize();

                return $items;
            },
            []
        );
    }
}
