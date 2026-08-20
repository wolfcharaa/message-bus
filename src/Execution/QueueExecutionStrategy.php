<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Execution;

use RuntimeException;
use Wolfcharaa\MessageBus\PublishedExecution;
use Wolfcharaa\MessageBus\Queue\BatchQueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueFailed;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\RetryPolicy;
use Wolfcharaa\MessageBus\Queue\RetryPolicyRegistryInterface;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;

final class QueueExecutionStrategy implements HandlerExecutionStrategyInterface
{
    public function execute(ExecutionRequest $request): HandlerExecutionResultInterface
    {
        $provider = $request->environment->queueProvider
            ?? throw new RuntimeException('Queue provider is required for async flow execution.');

        $transport = $request->flow->transport
            ?? throw new RuntimeException(\sprintf('Async flow `%s` has no transport configuration.', $request->flow->key));

        $queueMessages = [];
        $bindings = [];

        foreach ($request->bindings as $binding) {
            if ($binding->bindingId === null) {
                throw new RuntimeException('Async binding must have stable bindingId.');
            }

            $envelope = $request->context->envelope()->withFlowBinding($binding->flow, $binding->bindingId);
            $serialized = $request->environment->envelopeSerializer->serialize($envelope);
            $delivery = ($request->flow->delivery ?? new QueueDeliveryOptions())
                ->merge($binding->delivery)
                ->merge($request->options->delivery);
            $retryPolicyKey = $delivery->retryPolicy ?? RetryPolicySnapshot::DEFAULT_KEY;
            $retryPolicySnapshot = $this->retryPolicySnapshot(
                $retryPolicyKey,
                $request->environment->retryPolicyRegistry,
            );

            $queueMessages[] = new QueueMessage(
                $transport->transport,
                $transport->queue,
                $serialized,
                $envelope->messageId,
                $envelope->correlationId,
                $binding->flow,
                $binding->bindingId,
                $request->environment->clock->now()->modify('+' . ($delivery->delaySeconds ?? 0) . ' seconds'),
                $delivery->priority ?? 0,
                $retryPolicyKey,
                $retryPolicySnapshot,
            );
            $bindings[] = $binding;
        }

        if ($provider instanceof BatchQueueProviderInterface) {
            return $this->enqueueBatch($provider, $queueMessages, $bindings);
        }

        $results = [];
        foreach ($queueMessages as $index => $queueMessage) {
            $binding = $bindings[$index];
            try {
                $result = $provider->enqueue($queueMessage);
                $results[] = HandlerResult::success($binding->bindingId, $binding->action, PublishedExecution::queued($queueMessage, $result));
            } catch (\Throwable $e) {
                $results[] = HandlerResult::failure($binding->bindingId, $binding->action, new QueueEnqueueFailed($queueMessage, $e));
            }
        }

        return new HandlerExecutionResult(...$results);
    }

    /**
     * @param list<QueueMessage> $queueMessages
     * @param list<\Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition> $bindings
     */
    private function enqueueBatch(BatchQueueProviderInterface $provider, array $queueMessages, array $bindings): HandlerExecutionResultInterface
    {
        try {
            $batch = $provider->enqueueMany($queueMessages);
            $enqueueResults = $batch->all();
            $this->assertBatchResultCount($batch, $queueMessages);
        } catch (\Throwable $e) {
            $results = [];
            foreach ($queueMessages as $index => $queueMessage) {
                $binding = $bindings[$index];
                $results[] = HandlerResult::failure($binding->bindingId, $binding->action, new QueueEnqueueFailed($queueMessage, $e));
            }

            return new HandlerExecutionResult(...$results);
        }

        $results = [];
        foreach ($enqueueResults as $index => $enqueueResult) {
            $binding = $bindings[$index];
            $results[] = HandlerResult::success(
                $binding->bindingId,
                $binding->action,
                PublishedExecution::queued($queueMessages[$index], $enqueueResult),
            );
        }

        return new HandlerExecutionResult(...$results);
    }

    /** @param list<QueueMessage> $queueMessages */
    private function assertBatchResultCount(QueueBatchEnqueueResult $batch, array $queueMessages): void
    {
        if (\count($batch->all()) !== \count($queueMessages)) {
            throw new RuntimeException('Batch queue provider returned unexpected number of enqueue results.');
        }
    }

    private function retryPolicySnapshot(string $key, ?RetryPolicyRegistryInterface $registry): RetryPolicySnapshot
    {
        if ($registry !== null) {
            return RetryPolicySnapshot::fromPolicy($registry->get($key));
        }

        if ($key !== RetryPolicySnapshot::DEFAULT_KEY) {
            throw new RuntimeException(\sprintf(
                'Retry policy `%s` requires retry policy registry.',
                $key,
            ));
        }

        return RetryPolicySnapshot::fromPolicy(RetryPolicy::exponential(3, 30, 2.0, 300));
    }
}
