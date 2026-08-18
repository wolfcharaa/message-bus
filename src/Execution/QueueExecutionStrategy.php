<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use RuntimeException;
use Wolfcharaa\MessageBus\Queue\QueueMessage;

final class QueueExecutionStrategy implements HandlerExecutionStrategyInterface
{
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        $provider = $request->environment->queueProvider
            ?? throw new RuntimeException('Queue provider is required for async flow execution.');

        $transport = $request->flow->transport
            ?? throw new RuntimeException(\sprintf('Async flow `%s` has no transport configuration.', $request->flow->key));

        $results = [];

        foreach ($request->bindings as $binding) {
            if ($binding->bindingId === null) {
                throw new RuntimeException('Async binding must have stable bindingId.');
            }

            $envelope = $request->context->envelope()->withFlowBinding($binding->flow, $binding->bindingId);
            $serialized = $request->environment->envelopeSerializer->serialize($envelope);
            $delivery = ($request->flow->delivery ?? new \Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions())
                ->merge($binding->delivery)
                ->merge($request->options->delivery);

            $queueMessage = new QueueMessage(
                $transport->transport,
                $transport->queue,
                $serialized,
                $envelope->messageId,
                $envelope->correlationId,
                $binding->flow,
                $binding->bindingId,
                $request->environment->clock->now()->modify('+' . ($delivery->delaySeconds ?? 0) . ' seconds'),
                $delivery->priority ?? 0,
            );

            $result = $provider->enqueue($queueMessage);
            $results[] = HandlerResult::success($binding->bindingId, $binding->action, $result);
        }

        return new HandlerExecutionResult(...$results);
    }
}
