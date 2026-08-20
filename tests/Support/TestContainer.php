<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests\Support;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class TestContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private array $entries;

    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(array $entries = [], private readonly bool $autowireClasses = true)
    {
        $this->entries = $entries;
    }

    public function set(string $id, mixed $entry): self
    {
        $this->entries[$id] = $entry;
        unset($this->resolved[$id]);

        return $this;
    }

    public function get(string $id): mixed
    {
        if (\array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        if (\array_key_exists($id, $this->entries)) {
            $entry = $this->entries[$id];
            $service = $entry instanceof Closure ? $entry($this) : $entry;
            $this->resolved[$id] = $service;

            return $service;
        }

        if ($this->autowireClasses && \class_exists($id)) {
            $service = new $id();
            $this->resolved[$id] = $service;

            return $service;
        }

        throw new TestContainerNotFound(\sprintf('Test service `%s` was not found.', $id));
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->entries)
            || \array_key_exists($id, $this->resolved)
            || ($this->autowireClasses && \class_exists($id));
    }
}

final class TestContainerNotFound extends RuntimeException implements NotFoundExceptionInterface
{
}
