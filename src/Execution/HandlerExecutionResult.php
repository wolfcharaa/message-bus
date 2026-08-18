<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use BackedEnum;
use OutOfBoundsException;

/**
 * @implements HandlerExecutionResultInterface<mixed>
 */
final class HandlerExecutionResult implements HandlerExecutionResultInterface
{
    /** @var list<HandlerResultInterface<mixed>> */
    private array $results;

    /** @var array<string, HandlerResultInterface<mixed>> */
    private array $byBinding = [];

    /** @var array<class-string, HandlerResultInterface<mixed>> */
    private array $byAction = [];

    public function __construct(HandlerResultInterface ...$results)
    {
        $this->results = \array_values($results);

        foreach ($results as $result) {
            $this->byBinding[$result->bindingId()] = $result;
            $this->byAction[$result->actionClass()] = $result;
        }
    }

    /** @return list<HandlerResultInterface<mixed>> */
    public function all(): array
    {
        return $this->results;
    }

    public function get(string|BackedEnum $bindingId): mixed
    {
        $bindingId = $bindingId instanceof BackedEnum ? (string) $bindingId->value : $bindingId;

        return ($this->byBinding[$bindingId] ?? throw new OutOfBoundsException(\sprintf(
            'Handler result for binding `%s` was not found.',
            $bindingId,
        )))->result();
    }

    public function getByAction(string $actionClass): mixed
    {
        return ($this->byAction[$actionClass] ?? throw new OutOfBoundsException(\sprintf(
            'Handler result for action `%s` was not found.',
            $actionClass,
        )))->result();
    }

    public function successful(): array
    {
        return \array_values(\array_filter(
            $this->results,
            static fn (HandlerResultInterface $result): bool => $result->isSuccessful(),
        ));
    }

    public function failed(): array
    {
        return \array_values(\array_filter(
            $this->results,
            static fn (HandlerResultInterface $result): bool => !$result->isSuccessful(),
        ));
    }

    public function hasFailures(): bool
    {
        return $this->failed() !== [];
    }

    public function merge(self $result): self
    {
        return new self(...$this->results, ...$result->results);
    }
}
