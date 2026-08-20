<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;
use Throwable;

final class ContainerServiceInvalid extends RuntimeException implements ContainerExceptionInterface
{
    /**
     * @param non-empty-list<string> $serviceIds
     */
    public function __construct(
        public readonly array $serviceIds,
        public readonly string $role,
        public readonly string $expectedType,
        public readonly string $actualType,
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
            $this->actualType,
            $bindingId ?? $this->bindingId,
            $flow ?? $this->flow,
            $this,
        );
    }

    private function formatMessage(): string
    {
        $parts = [
            \sprintf(
                'MessageBus container service is invalid for role `%s`; checked ids: %s.',
                $this->role,
                $this->formatIds(),
            ),
            \sprintf('Expected `%s`, got `%s`.', $this->expectedType, $this->actualType),
        ];

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
