<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use BackedEnum;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Clock\WallClock;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\EnvelopeFactory;
use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Execution\ExecutionEnvironment;
use Wolfcharaa\MessageBus\Execution\ExecutionRequest;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResult;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionStrategyInterface;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Invoker\ReflectionCallableInvoker;
use Wolfcharaa\MessageBus\Message\MessageIdGenerator;
use Wolfcharaa\MessageBus\Message\RandomMessageIdGenerator;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueFailed;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\RetryPolicyRegistryInterface;
use Wolfcharaa\MessageBus\Registry\BindingNotFound;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryInterface;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlScope;

final class MessageBus implements MessageBusInterface
{
    private readonly EnvelopeFactory $envelopeFactory;
    private readonly ExecutionEnvironment $environment;
    private readonly ?WorkerRuntimeControlInterface $workerRuntimeControl;

    public function __construct(
        private readonly MessageRegistryInterface $registry,
        private readonly FlowRegistry $flows,
        private readonly ContainerInterface $container,
        ?QueueProviderInterface $queueProvider = null,
        ?EnvelopeSerializerInterface $envelopeSerializer = null,
        ?CallableInvokerInterface $invoker = null,
        ?MessageIdGenerator $messageIdGenerator = null,
        ?ClockInterface $clock = null,
        ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
        ?WorkerRuntimeControlInterface $workerRuntimeControl = null,
    ) {
        $queueProvider ??= $this->optionalService(
            [QueueProviderInterface::class, 'message_bus.queue_provider'],
            'queue provider',
            QueueProviderInterface::class,
        );
        $invoker ??= $this->optionalService(
            [CallableInvokerInterface::class, 'message_bus.invoker'],
            'callable invoker',
            CallableInvokerInterface::class,
        ) ?? new ReflectionCallableInvoker($container);
        $messageIdGenerator ??= $this->optionalService(
            [MessageIdGenerator::class, 'message_bus.message_id_generator'],
            'message id generator',
            MessageIdGenerator::class,
        ) ?? new RandomMessageIdGenerator();
        $clock ??= $this->optionalService(
            [ClockInterface::class, 'message_bus.clock'],
            'clock',
            ClockInterface::class,
        ) ?? new WallClock();
        $retryPolicyRegistry ??= $this->optionalService(
            [RetryPolicyRegistryInterface::class, 'message_bus.retry_policy_registry'],
            'retry policy registry',
            RetryPolicyRegistryInterface::class,
        );
        $workerRuntimeControl ??= $this->optionalService(
            [WorkerRuntimeControlInterface::class, 'message_bus.worker_runtime_control'],
            'worker runtime control',
            WorkerRuntimeControlInterface::class,
        );

        if ($envelopeSerializer === null) {
            $envelopeSerializer = $this->optionalService(
                [EnvelopeSerializerInterface::class, 'message_bus.envelope_serializer'],
                'envelope serializer',
                EnvelopeSerializerInterface::class,
            );
        }

        if ($envelopeSerializer === null) {
            if (!$registry instanceof MessageNameResolverInterface) {
                throw new RuntimeException('Default envelope serializer requires registry implementing MessageNameResolverInterface.');
            }

            $envelopeSerializer = new DefaultEnvelopeSerializer(new JsonMessageSerializer($registry));
        }

        $this->envelopeFactory = new EnvelopeFactory(
            $messageIdGenerator ?? new RandomMessageIdGenerator(),
            $clock,
        );
        $this->environment = new ExecutionEnvironment($invoker, $envelopeSerializer, $clock, $queueProvider, $retryPolicyRegistry);
        $this->workerRuntimeControl = $workerRuntimeControl;
    }

    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        $bindings = $this->syncBindings($message::class);
        $primary = \array_values(\array_filter(
            $bindings,
            static fn (HandlerBindingDefinition $binding): bool => $binding->primary === true,
        ));

        if (\count($primary) !== 1) {
            throw new BindingNotFound(\sprintf(
                'Message `%s` must have exactly one primary sync binding.',
                $message::class,
            ));
        }

        $binding = $primary[0];
        $result = $this->executeBindings($message, [$binding], $options, $causation);

        return $result->get($binding->bindingId ?? '');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        return $this->executeBindings($message, $this->syncBindings($message::class), $options, $causation);
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        $bindings = $this->asyncBindings($message::class);
        if ($bindings === []) {
            return PublishResult::empty();
        }

        if ($this->forceSync()) {
            return $this->executePublishedSync($message, $bindings, $options, $causation);
        }

        return $this->publishResult(
            $this->executeBindings($message, $bindings, $options, $causation),
        );
    }

    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        $result = PublishResult::empty();

        foreach ($messages as $item) {
            if ($item instanceof MessageBatchItem) {
                $message = $item->message;
                $itemOptions = $options->merge($item->options);
            } elseif (\is_object($item)) {
                $message = $item;
                $itemOptions = $options;
            } else {
                throw new \InvalidArgumentException('publishMany() expects objects or MessageBatchItem instances.');
            }

            try {
                $result = $result->merge($this->publish($message, $itemOptions, $causation));
            } catch (PublishFailed $e) {
                throw new PublishFailed($result->merge($e->result()), $e);
            }
        }

        return $result;
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        return $this->executeBindings(
            $message,
            $this->asyncBindings($message::class),
            $options,
            $causation,
            forceSequential: true,
        );
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        $bindingId = $bindingId instanceof BackedEnum ? (string) $bindingId->value : $bindingId;
        $binding = $this->registry->binding($bindingId);

        if (!$message instanceof $binding->message) {
            throw new RuntimeException(\sprintf(
                'Binding `%s` expects message `%s`, got `%s`.',
                $bindingId,
                $binding->message,
                $message::class,
            ));
        }

        $result = $this->executeBindings($message, [$binding], $options, $causation, forceSequential: true);

        return $result->get($bindingId);
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        if ($envelope->bindingId === null) {
            throw new RuntimeException('Envelope bindingId is required for worker execution.');
        }

        $binding = $this->registry->binding($envelope->bindingId);
        $flow = $this->flows->get($binding->flow);
        $context = $this->createContext($flow, $envelope);
        $request = new ExecutionRequest([$binding], $context, $flow, new PublishOptions(), $this->environment);
        $result = (new SequentialExecutionStrategy())->execute($request);

        return $result->get($binding->bindingId ?? '');
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     */
    private function executeBindings(
        object $message,
        array $bindings,
        PublishOptions $options,
        ?Envelope $causation,
        bool $forceSequential = false,
    ): HandlerExecutionResultInterface {
        if ($bindings === []) {
            throw new BindingNotFound(\sprintf('Message `%s` has no matching bindings.', $message::class));
        }

        $results = [];
        foreach ($this->groupByFlow($bindings) as $flowKey => $flowBindings) {
            $flow = $this->flows->get($flowKey);
            $envelope = $this->envelopeFactory->create(
                $message,
                $flow->key,
                \count($flowBindings) === 1 ? $flowBindings[0]->bindingId : null,
                $options,
                $causation,
            );
            $context = $this->createContext($flow, $envelope);
            $strategy = $forceSequential ? new SequentialExecutionStrategy() : $this->strategy($flow);
            $result = $strategy->execute(new ExecutionRequest($flowBindings, $context, $flow, $options, $this->environment));
            $results = [...$results, ...$result->all()];
        }

        return new HandlerExecutionResult(...$results);
    }

    /**
     * @param non-empty-list<HandlerBindingDefinition> $bindings
     */
    private function executePublishedSync(
        object $message,
        array $bindings,
        PublishOptions $options,
        ?Envelope $causation,
    ): PublishResult {
        $executions = [];
        $failures = [];

        foreach ($this->groupByFlow($bindings) as $flowKey => $flowBindings) {
            $flow = $this->flows->get($flowKey);
            $baseEnvelope = $this->envelopeFactory->create(
                $message,
                $flow->key,
                \count($flowBindings) === 1 ? $flowBindings[0]->bindingId : null,
                $options,
                $causation,
            );

            foreach ($flowBindings as $binding) {
                $envelope = $baseEnvelope->withFlowBinding($binding->flow, $binding->bindingId);
                $context = $this->createContext($flow, $envelope);
                $startedAt = $this->environment->clock->now();
                $started = \microtime(true);

                try {
                    (new SequentialExecutionStrategy())->execute(new ExecutionRequest(
                        [$binding],
                        $context,
                        $flow,
                        $options,
                        $this->environment,
                    ));
                    $finishedAt = $this->environment->clock->now();
                    $executions[] = PublishedExecution::sync(
                        $envelope,
                        $binding->bindingId ?? '',
                        QueueJobState::Succeeded,
                        $startedAt,
                        $finishedAt,
                        (int) \round((\microtime(true) - $started) * 1000),
                    );
                } catch (\Throwable $e) {
                    $transport = $flow->transport;
                    $failures[] = PublishFailure::fromThrowable(
                        $e,
                        $envelope->messageId,
                        $envelope->correlationId,
                        $binding->flow,
                        $binding->bindingId ?? '',
                        $transport?->transport ?? '',
                        $transport?->queue ?? '',
                    );
                }
            }
        }

        $result = new PublishResult($executions, $failures);
        if ($result->hasFailures()) {
            throw new PublishFailed($result);
        }

        return $result;
    }

    private function publishResult(HandlerExecutionResultInterface $executionResult): PublishResult
    {
        $executions = [];
        $failures = [];
        $previous = null;

        foreach ($executionResult->all() as $result) {
            if ($result->isSuccessful()) {
                $execution = $result->result();
                if ($execution instanceof PublishedExecution) {
                    $executions[] = $execution;
                }

                continue;
            }

            $error = $result->error();
            if ($error === null) {
                continue;
            }
            $previous ??= $error;
            $failures[] = $this->publishFailure($result->bindingId(), $error);
        }

        $publishResult = new PublishResult($executions, $failures);
        if ($publishResult->hasFailures()) {
            throw new PublishFailed($publishResult, $previous);
        }

        return $publishResult;
    }

    private function publishFailure(string $bindingId, \Throwable $error): PublishFailure
    {
        if ($error instanceof QueueEnqueueFailed) {
            $queueMessage = $error->queueMessage;

            return PublishFailure::fromThrowable(
                $error->getPrevious() ?? $error,
                $queueMessage->messageId,
                $queueMessage->correlationId,
                $queueMessage->flow,
                $queueMessage->bindingId,
                $queueMessage->transport,
                $queueMessage->queue,
            );
        }

        $binding = $this->registry->binding($bindingId);
        $flow = $this->flows->get($binding->flow);

        return PublishFailure::fromThrowable(
            $error,
            '',
            '',
            $binding->flow,
            $binding->bindingId ?? '',
            $flow->transport?->transport ?? '',
            $flow->transport?->queue ?? '',
        );
    }

    private function forceSync(): bool
    {
        $value = \getenv('MESSAGE_BUS_FORCE_SYNC');

        return $value !== false && $value !== '' && $value !== '0';
    }

    /** @return list<HandlerBindingDefinition> */
    private function syncBindings(string $messageClass): array
    {
        return \array_values(\array_filter(
            $this->registry->bindingsForMessage($messageClass),
            fn (HandlerBindingDefinition $binding): bool => $this->flows->get($binding->flow)->isSync(),
        ));
    }

    /** @return list<HandlerBindingDefinition> */
    private function asyncBindings(string $messageClass): array
    {
        return \array_values(\array_filter(
            $this->registry->bindingsForMessage($messageClass),
            fn (HandlerBindingDefinition $binding): bool => $this->flows->get($binding->flow)->isAsync(),
        ));
    }

    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @return array<string, non-empty-list<HandlerBindingDefinition>>
     */
    private function groupByFlow(array $bindings): array
    {
        $grouped = [];

        foreach ($bindings as $binding) {
            $grouped[$binding->flow][] = $binding;
        }

        return $grouped;
    }

    private function createContext(FlowDefinition $flow, Envelope $envelope): MessageContextInterface
    {
        if ($flow->contextFactory === null) {
            throw new RuntimeException(\sprintf('Flow `%s` has no context factory.', $flow->key));
        }

        $factory = $this->requiredService(
            [$flow->contextFactory],
            'context factory',
            MessageContextFactoryInterface::class,
            flow: $flow->key,
        );

        $context = $factory->create(
            $this,
            $envelope,
            $flow,
            WorkerRuntimeControlScope::current() ?? $this->workerRuntimeControl,
        );

        if (!$context instanceof $flow->contextInterface) {
            throw new RuntimeException(\sprintf(
                'Flow `%s` context factory returned `%s`, expected `%s`.',
                $flow->key,
                $context::class,
                $flow->contextInterface,
            ));
        }

        return $context;
    }

    private function strategy(FlowDefinition $flow): HandlerExecutionStrategyInterface
    {
        $strategy = $this->requiredService(
            [$flow->strategy],
            'execution strategy',
            HandlerExecutionStrategyInterface::class,
            flow: $flow->key,
        );

        return $strategy;
    }

    /**
     * @param non-empty-list<string> $ids
     */
    private function requiredService(
        array $ids,
        string $role,
        string $expectedType,
        ?string $bindingId = null,
        ?string $flow = null,
    ): object {
        $service = $this->optionalService($ids, $role, $expectedType, $bindingId, $flow);

        if ($service === null) {
            throw new ContainerServiceNotFound($ids, $role, $expectedType, $bindingId, $flow);
        }

        return $service;
    }

    /**
     * @param non-empty-list<string> $ids
     */
    private function optionalService(
        array $ids,
        string $role,
        string $expectedType,
        ?string $bindingId = null,
        ?string $flow = null,
    ): ?object {
        foreach ($ids as $id) {
            try {
                if (!$this->container->has($id)) {
                    continue;
                }

                $service = $this->container->get($id);
            } catch (NotFoundExceptionInterface $e) {
                throw new ContainerServiceNotFound([$id], $role, $expectedType, $bindingId, $flow, $e);
            } catch (ContainerExceptionInterface $e) {
                throw new ContainerServiceInvalid([$id], $role, $expectedType, 'container error', $bindingId, $flow, $e);
            }

            if (!$service instanceof $expectedType) {
                throw new ContainerServiceInvalid([$id], $role, $expectedType, $this->actualType($service), $bindingId, $flow);
            }

            return $service;
        }

        return null;
    }

    private function actualType(mixed $value): string
    {
        return \is_object($value) ? $value::class : \get_debug_type($value);
    }
}
