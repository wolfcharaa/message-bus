<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Exception;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

final class ContainerServiceNotFound extends RuntimeException implements NotFoundExceptionInterface
{
    /**
     * @param non-empty-list<string> $serviceIds
     */
    public function __construct(
        public readonly array $serviceIds,
        public readonly string $role,
        public readonly ?string $expectedType = null,
        public readonly ?string $bindingId = null,
        public readonly ?string $flow = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($this->formatMessage(), 0, $previous);
    }

    public function withContext(?string $role = null, ?string $bindingId = null, ?string $flow = null): self
    {
        return new self(
            $this->serviceIds,
            $role ?? $this->role,
            $this->expectedType,
            $bindingId ?? $this->bindingId,
            $flow ?? $this->flow,
            $this,
        );
    }

    private function formatMessage(): string
    {
        $parts = [
            \sprintf(
                'MessageBus container service was not found for role `%s`; checked ids: %s.',
                $this->role,
                $this->formatIds(),
            ),
        ];

        if ($this->expectedType !== null) {
            $parts[] = \sprintf('Expected `%s`.', $this->expectedType);
        }

        if ($this->bindingId !== null) {
            $parts[] = \sprintf('Binding `%s`.', $this->bindingId);
        }

        if ($this->flow !== null) {
            $parts[] = \sprintf('Flow `%s`.', $this->flow);
        }

        return \implode(' ', $parts);
    }

    private function formatIds(): string
    {
        return \implode(', ', \array_map(static fn (string $id): string => '`' . $id . '`', $this->serviceIds));
    }
}
