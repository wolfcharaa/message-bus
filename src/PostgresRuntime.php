<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter\Postgres;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\FailureClassifiers;
use Wolfcharaa\MessageBus\DurableRuntime;
use Wolfcharaa\MessageBus\HandlerServiceResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\Metadata\MetadataEnricher;
use Wolfcharaa\MessageBus\Metadata\MetadataSchema;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Observability\NullObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\NullRuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\NullRuntimeObserver;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\SystemClock;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;

/**
 * Ready process-local PostgreSQL composition for dispatch, workers and operations.
 *
 * The host owns the PDO lifetime, schema migration timing and process boundary.
 * Call `create()` with a fresh child PDO after `fork()`; do not reuse a runtime
 * inherited from the parent. Standard JSON/PHP-native codecs are derived from
 * the compiled registry when `codecs` is omitted.
 *
 * @api application
 */
final readonly class PostgresRuntime
{
    private function __construct(
        public PostgresDeliveryBackend $backend,
        public MessageCodecs $codecs,
        public DefaultMessageBus $bus,
        public DeliveryWorkerRuntime $worker,
        public PostgresSchemaManager $schemaManager,
        public DeliveryOperations $deliveries,
        public DeliveryMaintenance $deliveryMaintenance,
        public PostgresIdempotencyMaintenance $idempotencyMaintenance,
        public PostgresWorkerControl $workerControl,
    ) {}

    /**
     * Creates one cohesive runtime from host-owned process-local dependencies.
     *
     * @param array<class-string<Message<mixed>>, array<string, list<class-string>>> $nativeConcretePaths
     */
    public static function create(
        \PDO $pdo,
        CompiledRegistry $registry,
        HandlerServiceResolver $handlers,
        DeliveryRoutes $routes,
        DeliveryPolicies $policies,
        ?MessageCodecs $codecs = null,
        array $nativeConcretePaths = [],
        string $schema = 'public',
        MessageContextFactoryResolver $contextFactories = new MessageContextFactories(),
        HandlerMiddlewareResolver $middleware = new HandlerMiddlewares(),
        FailureClassifiers $failureClassifiers = new FailureClassifiers(),
        ClockInterface $clock = new SystemClock(),
        IdentityGenerator $identities = new RandomIdentityGenerator(),
        MetadataSchema $metadataSchema = new MetadataSchema(),
        ?MetadataEnricher $metadataEnricher = null,
        RuntimeObserver $observer = new NullRuntimeObserver(),
        ObserverFailureSink $observerFailureSink = new NullObserverFailureSink(),
        ?IdempotencyRuntime $idempotency = null,
        RuntimeFailureSink $runtimeFailureSink = new NullRuntimeFailureSink(),
    ): self {
        $resolvedCodecs = $codecs ?? MessageCodecs::fromRegistry($registry, $nativeConcretePaths);
        $backend = new PostgresDeliveryBackend($pdo, $identities, $schema);
        $bus = new DefaultMessageBus(
            registry: $registry,
            handlers: $handlers,
            routes: $routes,
            contextFactories: $contextFactories,
            middleware: $middleware,
            durable: new DurableRuntime($backend, $resolvedCodecs, $policies),
            clock: $clock,
            identities: $identities,
            metadataSchema: $metadataSchema,
            metadataEnricher: $metadataEnricher,
            observer: $observer,
            observerFailureSink: $observerFailureSink,
        );
        $worker = new DeliveryWorkerRuntime(
            backend: $backend,
            codecs: $resolvedCodecs,
            registry: $registry,
            handlers: $handlers,
            dispatcher: $bus,
            contextFactories: $contextFactories,
            middleware: $middleware,
            classifiers: $failureClassifiers,
            observer: $observer,
            observerFailureSink: $observerFailureSink,
            clock: $clock,
            idempotency: $idempotency,
            runtimeFailureSink: $runtimeFailureSink,
        );

        return new self(
            backend: $backend,
            codecs: $resolvedCodecs,
            bus: $bus,
            worker: $worker,
            schemaManager: new PostgresSchemaManager($pdo, $schema),
            deliveries: new DeliveryOperations($backend),
            deliveryMaintenance: new DeliveryMaintenance($backend),
            idempotencyMaintenance: new PostgresIdempotencyMaintenance($pdo, $schema),
            workerControl: new PostgresWorkerControl($pdo, $schema),
        );
    }
}
