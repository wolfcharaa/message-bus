<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationGraphContext;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOrigin;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticTarget;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRuleInterface;

final readonly class FlowContractValidationRule implements RegistryValidationRuleInterface
{
    /**
     * @param iterable<BindingPolicyRegistryInterface> $policyRegistries
     */
    public function __construct(
        private FlowContractRegistry $contracts,
        private MiddlewareRoleRegistry $middlewareRoles,
        private iterable $policyRegistries = [],
    ) {
    }

    public function validate(RegistryCompilationGraphContext $context): iterable
    {
        $diagnostics = [];
        $policyRegistries = $this->policyRegistries();

        foreach ($context->bindings as $binding) {
            $contract = $this->contracts->get($binding->flow);
            if ($contract === null) {
                continue;
            }

            $target = RegistryDiagnosticTarget::fromBinding($binding);
            $origin = RegistryDiagnosticOrigin::flow($binding->flow, 'message_bus.flow_contract');
            $flow = $context->flows?->all()[$binding->flow] ?? null;
            $middleware = $this->middleware($binding, $flow?->middleware ?? []);
            $positions = $this->middlewareRoles->firstRolePositions($middleware);

            if ($contract->allowedHandlerKinds !== [] && !\in_array($binding->kind, $contract->allowedHandlerKinds, true)) {
                $diagnostics[] = $this->error(
                    \sprintf('Flow `%s` does not allow `%s` binding `%s`.', $binding->flow, $binding->kind->value, $binding->bindingId),
                    $origin,
                    $target,
                    'Move this handler to a compatible flow or change the flow contract.',
                );
            }

            foreach ($contract->requiredRoles as $role) {
                if (!isset($positions[$role])) {
                    $diagnostics[] = $this->error(
                        \sprintf('Flow `%s` requires middleware role `%s` for binding `%s`.', $binding->flow, $role, $binding->bindingId),
                        $origin,
                        $target,
                        'Register a middleware role mapping or add middleware with the required role to the flow/binding.',
                    );
                }
            }

            foreach ($contract->requiredOrder as $order) {
                $before = $positions[$order['before']] ?? null;
                $after = $positions[$order['after']] ?? null;
                if ($before === null || $after === null) {
                    continue;
                }

                if ($before >= $after) {
                    $diagnostics[] = $this->error(
                        \sprintf(
                            'Flow `%s` requires middleware role `%s` before `%s` for binding `%s`.',
                            $binding->flow,
                            $order['before'],
                            $order['after'],
                            $binding->bindingId,
                        ),
                        $origin,
                        $target,
                        'Reorder flow/binding middleware so outer execution roles appear before inner roles.',
                    );
                }
            }

            if ($contract->requiresStableBindingId && $this->isAutoBindingId($binding)) {
                $diagnostics[] = $this->error(
                    \sprintf('Flow `%s` requires stable bindingId for `%s -> %s`.', $binding->flow, $binding->message, $binding->action),
                    $origin,
                    $target,
                    'Set an explicit bindingId on the handler attribute or manual binding.',
                );
            }

            if ($contract->requiresBindingOwner && $binding->owner === null) {
                $diagnostics[] = $this->error(
                    \sprintf('Flow `%s` requires binding owner metadata for binding `%s`.', $binding->flow, $binding->bindingId),
                    $origin,
                    $target,
                    'Set ownerKind and ownerId on the handler attribute or stamp the binding with BindingRegistrationContext.',
                );
            }

            foreach ($contract->requiredPolicies as $policyRegistryClass) {
                $policyRegistry = $policyRegistries[$policyRegistryClass] ?? null;
                if ($policyRegistry === null) {
                    $diagnostics[] = $this->error(
                        \sprintf('Flow `%s` requires policy registry `%s`.', $binding->flow, $policyRegistryClass),
                        $origin,
                        $target,
                        'Pass the required policy registry to FlowContractValidationRule.',
                    );

                    continue;
                }

                if ($binding->bindingId === null || !$policyRegistry->hasForBinding($binding->bindingId)) {
                    $diagnostics[] = $this->error(
                        \sprintf('Flow `%s` requires `%s` policy for binding `%s`.', $binding->flow, $policyRegistryClass, $binding->bindingId ?? ''),
                        $origin,
                        $target,
                        'Register a policy for every binding in this flow contract.',
                    );
                    continue;
                }

                if ($policyRegistry instanceof OwnedBindingPolicyRegistryInterface && $binding->owner !== null) {
                    $policyOwner = $policyRegistry->ownerForBinding($binding->bindingId);
                    if ($policyOwner !== null && !$binding->owner->equals($policyOwner)) {
                        $diagnostics[] = $this->error(
                            \sprintf(
                                'Flow `%s` binding `%s` owner `%s:%s` does not match policy owner `%s:%s`.',
                                $binding->flow,
                                $binding->bindingId,
                                $binding->owner->kind,
                                $binding->owner->id,
                                $policyOwner->kind,
                                $policyOwner->id,
                            ),
                            $origin,
                            $target,
                            'Register idempotency policy from the same owner as the protected binding.',
                        );
                    }
                }
            }
        }

        return $diagnostics;
    }

    /**
     * @return array<class-string<BindingPolicyRegistryInterface>, BindingPolicyRegistryInterface>
     */
    private function policyRegistries(): array
    {
        $indexed = [];
        foreach ($this->policyRegistries as $registry) {
            foreach (\class_implements($registry) ?: [] as $interface) {
                if (\is_a($interface, BindingPolicyRegistryInterface::class, true)) {
                    $indexed[$interface] = $registry;
                }
            }

            $indexed[$registry::class] = $registry;
        }

        return $indexed;
    }

    /**
     * @return list<class-string>
     */
    private function middleware(HandlerBindingDefinition $binding, array $flowMiddleware): array
    {
        return [...$flowMiddleware, ...$binding->middleware];
    }

    private function isAutoBindingId(HandlerBindingDefinition $binding): bool
    {
        return $binding->bindingId === null || \str_starts_with($binding->bindingId, 'auto.');
    }

    private function error(
        string $message,
        RegistryDiagnosticOrigin $origin,
        RegistryDiagnosticTarget $target,
        string $hint,
    ): RegistryDiagnostic {
        return RegistryDiagnostic::error(
            RegistryDiagnosticCodes::FLOW_CONTRACT_VIOLATION,
            $message,
            $origin,
            $target,
            $hint,
        );
    }
}
