<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use Wolfcharaa\MessageBus\Middleware\Pipeline;

final class SequentialExecutionStrategy implements HandlerExecutionStrategyInterface
{
    public function __construct(private readonly bool $failFast = true)
    {
    }

    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        $results = [];
        $bindings = $request->bindings;
        \usort($bindings, static fn ($a, $b): int => $b->priority <=> $a->priority);

        foreach ($bindings as $binding) {
            try {
                $pipeline = new Pipeline(
                    $binding,
                    $request->context,
                    $request->environment->invoker,
                    [...$request->flow->middleware, ...$binding->middleware],
                );
                $results[] = HandlerResult::success(
                    $binding->bindingId ?? '',
                    $binding->action,
                    $pipeline->continue(),
                );
            } catch (\Throwable $e) {
                if ($this->failFast) {
                    throw $e;
                }

                $results[] = HandlerResult::failure($binding->bindingId ?? '', $binding->action, $e);
            }
        }

        return new HandlerExecutionResult(...$results);
    }
}
