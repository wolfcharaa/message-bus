<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver;
use Wolfcharaa\MessageBus\Context\MessageContextSeed;
use Wolfcharaa\MessageBus\Context\NullCancellationSignal;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Event;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\MessageBus as MessageBusContract;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Contract\Request;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Exception\CommandNotAccepted;
use Wolfcharaa\MessageBus\Exception\PublicationFailed;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeObserver;
use Wolfcharaa\MessageBus\Internal\Runtime\HandlerPipeline;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Metadata\MetadataEnricher;
use Wolfcharaa\MessageBus\Metadata\MetadataSchema;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Observability\NullObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\NullRuntimeObserver;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Receipt\CommandReceipt;
use Wolfcharaa\MessageBus\Receipt\PublicationEntry;
use Wolfcharaa\MessageBus\Receipt\PublicationOutcome;
use Wolfcharaa\MessageBus\Receipt\PublicationReceipt;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBinding;
use Wolfcharaa\MessageBus\Registry\MessageRole;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;

/**
 * Resolves one compiler-validated handler from the current application scope.
 *
 * Implementations must not suppress failures raised by the underlying DI
 * container. A failure from get() remains observable through the dispatch
 * exception chain.
 *
 * @api spi
 */
interface HandlerServiceResolver
{
    /** @param class-string $class */
    public function get(string $class): object;

    /** @param class-string $class */
    public function has(string $class): bool;
}

/**
 * Exact fixed object map for applications that do not need scoped resolution.
 *
 * @api application
 */
final readonly class HandlerServices implements HandlerServiceResolver
{
    /** @param array<class-string, object> $services */
    public function __construct(private array $services) {}

    /** @param class-string $class */
    public function get(string $class): object
    {
        $service = $this->services[$class] ?? null;
        if (!$service instanceof $class) {
            throw new \LogicException('No compatible handler service is registered for ' . $class . '.');
        }

        return $service;
    }

    /** @param class-string $class */
    public function has(string $class): bool
    {
        return isset($this->services[$class]) && $this->services[$class] instanceof $class;
    }
}

/**
 *  Cohesive durable capability required by Command and durable Event routes.
 *
 * @api application
 */
final readonly class DurableRuntime
{
    public function __construct(
        public DurableDeliveryBackend $backend,
        public MessageCodecs $codecs,
        public DeliveryPolicies $policies,
    ) {}
}

/**
 *  Immutable package clock backed by the PHP wall clock.
 *
 * @api adapter
 */
final readonly class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}

/**
 *  Main v7 runtime implementing the single generic dispatch boundary.
 *
 * @api application
 */
final class DefaultMessageBus implements MessageBusContract
{
    /** @var list<MessageMetadata> */
    private array $dispatchStack = [];

    private readonly RuntimeObserver $observer;

    public function __construct(
        private readonly CompiledRegistry $registry,
        private readonly HandlerServiceResolver $handlers,
        private readonly DeliveryRoutes $routes,
        private readonly MessageContextFactoryResolver $contextFactories = new MessageContextFactories(),
        private readonly HandlerMiddlewareResolver $middleware = new HandlerMiddlewares(),
        private readonly ?DurableRuntime $durable = null,
        private readonly ClockInterface $clock = new SystemClock(),
        private readonly IdentityGenerator $identities = new RandomIdentityGenerator(),
        private readonly MetadataSchema $metadataSchema = new MetadataSchema(),
        private readonly ?MetadataEnricher $metadataEnricher = null,
        RuntimeObserver $observer = new NullRuntimeObserver(),
        ObserverFailureSink $observerFailureSink = new NullObserverFailureSink(),
    ) {
        $this->observer = new BestEffortRuntimeObserver($observer, $observerFailureSink);
        foreach ($this->registry->bindings() as $messageClass => $bindings) {
            foreach ($bindings as $binding) {
                if (!$this->handlers->has($binding->handlerClass)) {
                    throw new \InvalidArgumentException('Missing handler service: ' . $binding->handlerClass);
                }
                if ($binding->contextClass !== null && !$this->contextFactories->has($binding->contextClass)) {
                    throw new \InvalidArgumentException('Missing context factory: ' . $binding->contextClass);
                }
                if ($binding->role === MessageRole::Query || $binding->role === MessageRole::Request) {
                    continue;
                }
                $route = $this->routes->route($binding);
                if ($binding->role === MessageRole::Command && $route->mode !== DeliveryMode::Durable) {
                    throw new \InvalidArgumentException('Command route must be durable: ' . $messageClass);
                }
                if ($route->mode === DeliveryMode::Durable) {
                    if (!$this->durable instanceof DurableRuntime || !$this->durable->codecs->canEncode($messageClass)) {
                        throw new \InvalidArgumentException('Durable message has no codec/runtime: ' . $messageClass);
                    }
                    $this->durable->policies->get($route->policy);
                }
            }
        }
    }

    public function dispatch(Message $message): mixed
    {
        $metadata = $this->metadata($message);
        $started = hrtime(true);
        $this->dispatchStack[] = $metadata;
        $this->observer->observe(new RuntimeEvent(
            RuntimeEventType::DispatchStarted,
            $this->clock->now(),
            $metadata->messageId,
            correlationId: $metadata->correlationId,
            causationId: $metadata->causationId,
            messageName: $message::class,
        ));

        try {
            $result = match (true) {
                $message instanceof Query, $message instanceof Request => $this->dispatchSynchronous($message, $metadata),
                $message instanceof Command => $this->dispatchCommand($message, $metadata),
                $message instanceof Event => $this->dispatchEvent($message, $metadata),
                default => throw new \LogicException('Message has no supported role.'),
            };
            $this->observer->observe(new RuntimeEvent(
                RuntimeEventType::DispatchCompleted,
                $this->clock->now(),
                $metadata->messageId,
                correlationId: $metadata->correlationId,
                causationId: $metadata->causationId,
                messageName: $message::class,
                durationMicroseconds: $this->durationMicroseconds($started),
                outcome: 'completed',
            ));

            return $result;
        } catch (\Throwable $failure) {
            $this->observer->observe(new RuntimeEvent(
                RuntimeEventType::DispatchFailed,
                $this->clock->now(),
                $metadata->messageId,
                correlationId: $metadata->correlationId,
                causationId: $metadata->causationId,
                messageName: $message::class,
                durationMicroseconds: $this->durationMicroseconds($started),
                outcome: 'failed',
                failureClass: $failure::class,
                errorFingerprint: hash('sha256', $failure::class),
            ));

            throw $failure;
        } finally {
            array_pop($this->dispatchStack);
        }
    }

    /** @param Message<mixed> $message */
    private function dispatchSynchronous(Message $message, MessageMetadata $metadata): mixed
    {
        $bindings = $this->registry->handlersFor($message);
        if (\count($bindings) !== 1) {
            throw new \LogicException('Synchronous messages require exactly one compiled handler.');
        }

        return $this->invoke($bindings[0], $message, $metadata);
    }

    private function dispatchCommand(Command $message, MessageMetadata $metadata): CommandReceipt
    {
        $bindings = $this->registry->handlersFor($message);
        if (\count($bindings) !== 1) {
            throw new CommandNotAccepted('Command has no unique compiled recipient.');
        }
        $binding = $bindings[0];
        $route = $this->routes->route($binding);
        if ($route->mode !== DeliveryMode::Durable) {
            throw new CommandNotAccepted('Command delivery route must be durable.');
        }

        try {
            $entry = $this->accept($binding, $message, $metadata, $route->policy, $route->lane);
        } catch (\Throwable $failure) {
            throw new CommandNotAccepted('Command could not reach durable acceptance.', 0, $failure);
        }

        return new CommandReceipt($metadata->messageId, $entry);
    }

    private function dispatchEvent(Event $message, MessageMetadata $metadata): PublicationReceipt
    {
        $entries = [];
        $failures = [];
        foreach ($this->registry->handlersFor($message) as $binding) {
            $route = $this->routes->route($binding);

            try {
                if ($route->mode === DeliveryMode::Durable) {
                    $entries[] = $this->accept($binding, $message, $metadata, $route->policy, $route->lane);
                } else {
                    $this->invoke($binding, $message, $metadata);
                    $entries[] = new PublicationEntry(
                        $binding->id,
                        $route->mode === DeliveryMode::TestInline
                            ? PublicationOutcome::TestInlineCompleted
                            : PublicationOutcome::Completed,
                    );
                }
            } catch (\Throwable $failure) {
                $entries[] = new PublicationEntry(
                    $binding->id,
                    PublicationOutcome::Failed,
                    failureCategory: $failure::class,
                    failureSummary: substr($failure->getMessage(), 0, 512),
                );
                $failures[] = $failure;
            }
        }

        $receipt = new PublicationReceipt($metadata->messageId, $entries);
        if (!$receipt->isComplete()) {
            throw new PublicationFailed($receipt, $failures);
        }

        return $receipt;
    }

    /** @param Message<mixed> $message */
    private function accept(
        HandlerBinding $binding,
        Message $message,
        MessageMetadata $metadata,
        string $policy,
        string $lane,
    ): PublicationEntry {
        $runtime = $this->durable
            ?? throw new \LogicException('Durable runtime capability is not configured.');
        $deliveryId = $this->identities->deliveryId();
        $acceptance = $runtime->backend->accept(new DeliveryDraft(
            deliveryId: $deliveryId,
            metadata: $metadata,
            payload: $runtime->codecs->encode($message),
            targetId: $binding->id,
            handlerClass: $binding->handlerClass,
            handlerMethod: $binding->method,
            lane: $lane,
            policy: $runtime->policies->get($policy),
            role: $binding->role,
        ));
        $outcome = $acceptance->state === DeliveryState::Staged
            ? PublicationOutcome::Staged
            : PublicationOutcome::Accepted;
        $this->observer->observe(new RuntimeEvent(
            RuntimeEventType::DeliveryAccepted,
            $this->clock->now(),
            $metadata->messageId,
            $deliveryId,
            correlationId: $metadata->correlationId,
            causationId: $metadata->causationId,
            messageName: $message::class,
            targetId: $binding->id,
            route: $lane,
            outcome: $acceptance->state->value,
        ));

        return new PublicationEntry($binding->id, $outcome, $acceptance->deliveryId);
    }

    /** @param Message<mixed> $message */
    private function invoke(HandlerBinding $binding, Message $message, MessageMetadata $metadata): mixed
    {
        $service = $this->handlers->get($binding->handlerClass);
        $seed = new MessageContextSeed($this, $metadata, new NullCancellationSignal());
        $contextType = $binding->contextClass ?? MessageContext::class;
        $context = $this->contextFactories->factory($contextType)->create($seed);
        $method = new \ReflectionMethod($service, $binding->method);
        $handler = $binding->contextClass === null
            ? static fn(): mixed => $method->invoke($service, $message)
            : static fn(): mixed => $method->invoke($service, $message, $context);

        return (new HandlerPipeline($this->middleware->all()))->invoke($message, $context, $handler);
    }

    /** @param Message<mixed> $message */
    private function metadata(Message $message): MessageMetadata
    {
        $parent = $this->dispatchStack === []
            ? null
            : $this->dispatchStack[array_key_last($this->dispatchStack)];
        $application = $this->metadataEnricher?->enrich($message) ?? [];
        $this->metadataSchema->validate($application);

        return new MessageMetadata(
            messageId: $this->identities->messageId(),
            correlationId: $parent->correlationId ?? $this->identities->correlationId(),
            causationId: $parent?->messageId,
            createdAt: $this->clock->now(),
            application: $application,
        );
    }

    private function durationMicroseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000));
    }
}
