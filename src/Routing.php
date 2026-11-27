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
        if ($this->policy === '') {
            throw new \InvalidArgumentException('DeliveryRoute policy must not be empty.');
        }
        if ($this->lane === '') {
            throw new \InvalidArgumentException('DeliveryRoute lane must not be empty.');
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
                        $route = $this->routes->route($binding);
                        $diagnostics[] = $this->error(
                            'MBC001',
                            'An inline Query or Request target has a DeliveryRoute that runtime dispatch will not use.',
                            $binding->id,
                            expected: 'no DeliveryRoute for a ' . $binding->role->value . ' target',
                            actual: 'route mode=' . $route->mode->value
                                . ', policy=' . $route->policy
                                . ', lane=' . $route->lane,
                        );
                    }

                    continue;
                }
                if (!$this->routes->has($binding)) {
                    $diagnostics[] = $this->error(
                        'MBC002',
                        'A Command or Event target has no configured DeliveryRoute.',
                        $binding->id,
                        expected: 'one DeliveryRoute for this ' . $binding->role->value . ' target',
                        actual: 'no route is configured',
                    );

                    continue;
                }
                $route = $this->routes->route($binding);
                if ($binding->role === MessageRole::Command && $route->mode !== DeliveryMode::Durable) {
                    $diagnostics[] = $this->error(
                        'MBC003',
                        'A Command target is configured with a non-durable delivery mode.',
                        $binding->id,
                        expected: 'route mode=' . DeliveryMode::Durable->value,
                        actual: 'route mode=' . $route->mode->value,
                    );

                    continue;
                }
                if ($route->mode !== DeliveryMode::Durable) {
                    continue;
                }
                if (!$this->policies->has($route->policy)) {
                    $diagnostics[] = $this->error(
                        'MBC004',
                        'A durable route references a DeliveryPolicy that is not registered.',
                        $binding->id,
                        expected: 'a registered DeliveryPolicy name',
                        actual: 'policy "' . $route->policy . '" is not registered',
                    );

                    continue;
                }
                $policy = $this->policies->get($route->policy);
                if ($policy->idempotent && !$this->idempotency instanceof IdempotencyRuntime) {
                    $diagnostics[] = $this->error(
                        'MBC005',
                        'An idempotent durable target has no IdempotencyRuntime atomic boundary.',
                        $binding->id,
                        expected: IdempotencyRuntime::class . ' configured with an atomic boundary',
                        actual: 'IdempotencyRuntime is not configured',
                    );
                } elseif ($policy->idempotent
                    && $this->idempotency instanceof IdempotencyRuntime
                    && !$this->idempotency->identities->has($binding->id)) {
                    $diagnostics[] = $this->error(
                        'MBC008',
                        'An idempotent durable target has no exact target-scoped identity resolver.',
                        $binding->id,
                        expected: 'resolver for target ' . $binding->id,
                        actual: 'no resolver is registered for this target',
                    );
                }
                if ($policy->retryLostAttempts && !$policy->idempotent) {
                    $diagnostics[] = $this->error(
                        'MBC006',
                        'A DeliveryPolicy enables lost-attempt retry without idempotent execution.',
                        $binding->id,
                        expected: 'retryLostAttempts=true requires idempotent=true',
                        actual: 'retryLostAttempts=true and idempotent=false',
                    );
                }
            }
        }
        if ($this->idempotency instanceof IdempotencyRuntime) {
            foreach ($this->idempotency->identities->targetIds() as $targetId) {
                if (!isset($targets[$targetId])) {
                    $diagnostics[] = $this->error(
                        'MBC007',
                        'An idempotency identity resolver references a target absent from the compiled registry.',
                        $targetId,
                        expected: 'resolver target ID exists in the compiled registry',
                        actual: 'target ID is not compiled',
                    );
                }
            }
        }

        return $diagnostics;
    }

    private function error(
        string $code,
        string $message,
        string $symbol,
        ?string $expected = null,
        ?string $actual = null,
    ): RegistryDiagnostic {
        return new RegistryDiagnostic(
            $code,
            DiagnosticSeverity::Error,
            $this->detailedMessage($code, $message, $symbol, $expected, $actual),
            $symbol,
            $code,
            'target',
            $expected,
            $actual,
        );
    }

    private function detailedMessage(
        string $code,
        string $summary,
        string $symbol,
        ?string $expected,
        ?string $actual,
    ): string {
        $parts = [
            '[' . $code . '] ' . $summary,
            'Target: ' . $symbol,
        ];
        if ($expected !== null) {
            $parts[] = 'Expected: ' . $expected;
        }
        if ($actual !== null) {
            $parts[] = 'Actual: ' . $actual;
        }

        return implode(' ', array_map(
            static fn(string $part): string => rtrim($part, ". \t\n\r\0\x0B") . '.',
            $parts,
        ));
    }
}
