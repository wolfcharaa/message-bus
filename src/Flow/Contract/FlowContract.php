<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

use BackedEnum;
use Wolfcharaa\MessageBus\Registry\HandlerKind;

final readonly class FlowContract
{
    /**
     * @param list<string> $requiredRoles
     * @param list<array{before: string, after: string}> $requiredOrder
     * @param list<class-string<BindingPolicyRegistryInterface>> $requiredPolicies
     * @param list<HandlerKind> $allowedHandlerKinds
     */
    private function __construct(
        public string $flow,
        public array $requiredRoles = [],
        public array $requiredOrder = [],
        public bool $requiresStableBindingId = false,
        public bool $requiresBindingOwner = false,
        public array $requiredPolicies = [],
        public array $allowedHandlerKinds = [],
    ) {
    }

    public static function forFlow(string|BackedEnum $flow): self
    {
        return new self(self::normalize($flow));
    }

    public function requiresRole(string|BackedEnum $role): self
    {
        return new self(
            $this->flow,
            [...$this->requiredRoles, self::normalize($role)],
            $this->requiredOrder,
            $this->requiresStableBindingId,
            $this->requiresBindingOwner,
            $this->requiredPolicies,
            $this->allowedHandlerKinds,
        );
    }

    public function requiresOrder(string|BackedEnum $before, string|BackedEnum $after): self
    {
        return new self(
            $this->flow,
            $this->requiredRoles,
            [...$this->requiredOrder, ['before' => self::normalize($before), 'after' => self::normalize($after)]],
            $this->requiresStableBindingId,
            $this->requiresBindingOwner,
            $this->requiredPolicies,
            $this->allowedHandlerKinds,
        );
    }

    public function requiresStableBindingId(bool $required = true): self
    {
        return new self(
            $this->flow,
            $this->requiredRoles,
            $this->requiredOrder,
            $required,
            $this->requiresBindingOwner,
            $this->requiredPolicies,
            $this->allowedHandlerKinds,
        );
    }

    public function requiresBindingOwner(bool $required = true): self
    {
        return new self(
            $this->flow,
            $this->requiredRoles,
            $this->requiredOrder,
            $this->requiresStableBindingId,
            $required,
            $this->requiredPolicies,
            $this->allowedHandlerKinds,
        );
    }

    /** @param class-string<BindingPolicyRegistryInterface> $policyRegistry */
    public function requiresPolicy(string $policyRegistry): self
    {
        return new self(
            $this->flow,
            $this->requiredRoles,
            $this->requiredOrder,
            $this->requiresStableBindingId,
            $this->requiresBindingOwner,
            [...$this->requiredPolicies, $policyRegistry],
            $this->allowedHandlerKinds,
        );
    }

    public function allowsHandlerKinds(HandlerKind ...$kinds): self
    {
        return new self(
            $this->flow,
            $this->requiredRoles,
            $this->requiredOrder,
            $this->requiresStableBindingId,
            $this->requiresBindingOwner,
            $this->requiredPolicies,
            $kinds,
        );
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
