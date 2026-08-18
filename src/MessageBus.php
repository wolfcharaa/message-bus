<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use BackedEnum;
use Psr\Clock\ClockInterface;
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
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Invoker\InstantiatingServiceResolver;
use Wolfcharaa\MessageBus\Invoker\ReflectionCallableInvoker;
use Wolfcharaa\MessageBus\Invoker\ServiceResolverInterface;
use Wolfcharaa\MessageBus\Message\MessageIdGenerator;
use Wolfcharaa\MessageBus\Message\RandomMessageIdGenerator;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Registry\BindingNotFound;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryInterface;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;

final class MessageBus implements MessageBusInterface
{
    private readonly EnvelopeFactory $envelopeFactory;
    private readonly ExecutionEnvironment $environment;

    public function __construct(
        private readonly MessageRegistryInterface $registry,
        private readonly FlowRegistry $flows = new FlowRegistry(),
        ?QueueProviderInterface $queueProvider = null,
        ?EnvelopeSerializerInterface $envelopeSerializer = null,
        ?CallableInvokerInterface $invoker = null,
        ?ServiceResolverInterface $resolver = null,
        ?MessageIdGenerator $messageIdGenerator = null,
        ?ClockInterface $clock = null,
    ) {
        $resolver ??= new InstantiatingServiceResolver();
        $invoker ??= new ReflectionCallableInvoker($resolver);
        $clock ??= new WallClock();

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
        $this->environment = new ExecutionEnvironment($invoker, $envelopeSerializer, $clock, $queueProvider);
        $this->resolver = $resolver;
    }

    private readonly ServiceResolverInterface $resolver;

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
    ): void {
        $this->executeBindings($message, $this->asyncBindings($message::class), $options, $causation);
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

        $factory = $this->resolver->get($flow->contextFactory);

        if (!$factory instanceof MessageContextFactoryInterface) {
            throw new RuntimeException(\sprintf(
                'Flow `%s` context factory `%s` must implement `%s`.',
                $flow->key,
                $flow->contextFactory,
                MessageContextFactoryInterface::class,
            ));
        }

        $context = $factory->create($this, $envelope, $flow);

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
        $strategy = $this->resolver->get($flow->strategy);

        if (!$strategy instanceof HandlerExecutionStrategyInterface) {
            throw new RuntimeException(\sprintf(
                'Flow `%s` strategy `%s` must implement `%s`.',
                $flow->key,
                $flow->strategy,
                HandlerExecutionStrategyInterface::class,
            ));
        }

        return $strategy;
    }
}
