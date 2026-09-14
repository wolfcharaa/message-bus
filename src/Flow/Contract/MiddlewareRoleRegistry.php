<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

use BackedEnum;

final class MiddlewareRoleRegistry
{
    /** @var array<class-string, list<string>> */
    private array $rolesByMiddleware = [];

    public function register(string $middlewareClass, string|BackedEnum ...$roles): self
    {
        $normalized = \array_values(\array_unique(\array_map(
            static fn (string|BackedEnum $role): string => $role instanceof BackedEnum ? (string) $role->value : $role,
            $roles,
        )));

        $this->rolesByMiddleware[$middlewareClass] = \array_values(\array_unique([
            ...($this->rolesByMiddleware[$middlewareClass] ?? []),
            ...$normalized,
        ]));

        return $this;
    }

    /** @return list<string> */
    public function rolesFor(string $middlewareClass): array
    {
        return $this->rolesByMiddleware[$middlewareClass] ?? [];
    }

    /**
     * @param list<class-string> $middleware
     * @return array<string, int>
     */
    public function firstRolePositions(array $middleware): array
    {
        $positions = [];
        foreach ($middleware as $index => $middlewareClass) {
            foreach ($this->rolesFor($middlewareClass) as $role) {
                $positions[$role] ??= $index;
            }
        }

        return $positions;
    }
}
