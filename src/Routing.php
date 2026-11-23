<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Routing;

use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\DiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\HandlerBinding;
use Wolfcharaa\MessageBus\Registry\MessageRole;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRule;

/**
 *  Execution boundary selected for one Command owner or Event subscription.
 *
 * @api application
 */
enum DeliveryMode: string
{
    case Inline = 'inline';
    case Durable = 'durable';
    case TestInline = 'test_inline';
}

/**
 *  Immutable route for one independently tracked target.
 *
 * @api application
 */
final readonly class DeliveryRoute
{
    public function __construct(
        public DeliveryMode $mode,
        public string $policy = 'default',
        public string $lane = 'default',
    ) {
        if ($this->policy === '' || $this->lane === '') {
            throw new \InvalidArgumentException('Delivery route policy and lane must not be empty.');
        }
    }
}

/**
 *  Complete deterministic route map; no environment fallback occurs at runtime.
 *
 * @api application
 */
final readonly class DeliveryRoutes
{
    /** @param array<string, DeliveryRoute> $routes Binding ID to route. */
    public function __construct(private array $routes) {}

    public function route(HandlerBinding $binding): DeliveryRoute
    {
        return $this->routes[$binding->id]
            ?? throw new \LogicException('No DeliveryRoute is configured for target ' . $binding->id . '.');
    }

    public function has(HandlerBinding $binding): bool
    {
        return isset($this->routes[$binding->id]);
    }
}

/**
 * Validates the complete route, policy and target-scoped idempotency plan.
 *
 * Add this rule to RegistryCompiler in the application composition root.
 *
 * @api tooling
 */
final readonly class RuntimeConfigurationRule implements RegistryValidationRule
{
    public function __construct(
        private DeliveryRoutes $routes,
        private DeliveryPolicies $policies,
        private ?IdempotencyRuntime $idempotency = null,
    ) {}

    public function validate(CompiledRegistry $registry): array
    {
        $diagnostics = [];
        $targets = [];
        foreach ($registry->bindings() as $bindings) {
            foreach ($bindings as $binding) {
                $targets[$binding->id] = true;
                if ($binding->role === MessageRole::Query || $binding->role === MessageRole::Request) {
                    if ($this->routes->has($binding)) {
                        $diagnostics[] = $this->error(
                            'MBC001',
                            'Query and Request targets execute inline and must not have a DeliveryRoute.',
                            $binding->id,
                        );
                    }

                    continue;
                }
                if (!$this->routes->has($binding)) {
                    $diagnostics[] = $this->error('MBC002', 'Command and Event targets require a DeliveryRoute.', $binding->id);

                    continue;
                }
                $route = $this->routes->route($binding);
                if ($binding->role === MessageRole::Command && $route->mode !== DeliveryMode::Durable) {
                    $diagnostics[] = $this->error('MBC003', 'Command targets require durable delivery.', $binding->id);

                    continue;
                }
                if ($route->mode !== DeliveryMode::Durable) {
                    continue;
                }
                if (!$this->policies->has($route->policy)) {
                    $diagnostics[] = $this->error('MBC004', 'Durable route references an unknown DeliveryPolicy.', $binding->id);

                    continue;
                }
                $policy = $this->policies->get($route->policy);
                if ($policy->idempotent
                    && (!$this->idempotency instanceof IdempotencyRuntime
                        || !$this->idempotency->identities->has($binding->id))) {
                    $diagnostics[] = $this->error(
                        'MBC005',
                        'Idempotent target requires one target-scoped resolver and atomic boundary.',
                        $binding->id,
                    );
                }
                if ($policy->retryLostAttempts && !$policy->idempotent) {
                    $diagnostics[] = $this->error(
                        'MBC006',
                        'Automatic lost-attempt retry requires an idempotent target policy.',
                        $binding->id,
                    );
                }
            }
        }
        if ($this->idempotency instanceof IdempotencyRuntime) {
            foreach ($this->idempotency->identities->targetIds() as $targetId) {
                if (!isset($targets[$targetId])) {
                    $diagnostics[] = $this->error(
                        'MBC007',
                        'Idempotency resolver references an unknown compiled target.',
                        $targetId,
                    );
                }
            }
        }

        return $diagnostics;
    }

    private function error(string $code, string $message, string $symbol): RegistryDiagnostic
    {
        return new RegistryDiagnostic($code, DiagnosticSeverity::Error, $message, $symbol);
    }
}
