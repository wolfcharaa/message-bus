<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Runtime;

use PDO;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Discovery\ClassProviderInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\MessageBusQueueWorker;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresMessageConsumer;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueProvider;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorage;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;
use Wolfcharaa\MessageBus\Queue\QueueStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Queue\QueueWorkerInterface;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\RetryPolicyRegistryInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryInterface;
use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerControlService;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlStorage;

final class MessageBusRuntime
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ?QueueWorkerRunner $runner = null,
        private readonly ?QueueProviderInterface $provider = null,
        private readonly ?MessageConsumerInterface $consumer = null,
        private readonly ?QueueWorkerInterface $worker = null,
        private readonly ?QueueStatusRepositoryInterface $queueStatus = null,
        private readonly ?QueueJobControlInterface $queueControl = null,
        private readonly ?WorkerControlRuntime $workerControlRuntime = null,
    ) {
    }

    public function bus(): MessageBusInterface
    {
        return $this->bus;
    }

    public function runner(): ?QueueWorkerRunner
    {
        return $this->runner;
    }

    public function provider(): ?QueueProviderInterface
    {
        return $this->provider;
    }

    public function consumer(): ?MessageConsumerInterface
    {
        return $this->consumer;
    }

    public function worker(): ?QueueWorkerInterface
    {
        return $this->worker;
    }

    public function queueStatus(): ?QueueStatusRepositoryInterface
    {
        return $this->queueStatus;
    }

    public function queueControl(): ?QueueJobControlInterface
    {
        return $this->queueControl;
    }

    public function workerControlRuntime(): ?WorkerControlRuntime
    {
        return $this->workerControlRuntime;
    }

    public static function fromContainer(ContainerInterface $container): self
    {
        return new self(
            self::required($container, MessageBusInterface::class, 'message_bus.bus', MessageBusInterface::class, 'message bus'),
            self::optional($container, QueueWorkerRunner::class, 'message_bus.runner', QueueWorkerRunner::class, 'queue worker runner'),
            self::optional($container, QueueProviderInterface::class, 'message_bus.queue_provider', QueueProviderInterface::class, 'queue provider'),
            self::optional($container, MessageConsumerInterface::class, 'message_bus.consumer', MessageConsumerInterface::class, 'message consumer'),
            self::optional($container, QueueWorkerInterface::class, 'message_bus.worker', QueueWorkerInterface::class, 'queue worker'),
            self::optional($container, QueueStatusRepositoryInterface::class, 'message_bus.queue_status', QueueStatusRepositoryInterface::class, 'queue status repository'),
            self::optional($container, QueueJobControlInterface::class, 'message_bus.queue_control', QueueJobControlInterface::class, 'queue job control'),
            self::optional($container, WorkerControlRuntime::class, 'message_bus.worker_control_runtime', WorkerControlRuntime::class, 'worker control runtime'),
        );
    }

    public static function postgres(
        PDO $pdo,
        MessageRegistryInterface $registry,
        ContainerInterface $container,
        ?FlowRegistry $flows = null,
        string $tableName = 'message_bus__queue_jobs',
        ?EnvelopeSerializerInterface $envelopeSerializer = null,
        ?RetryPolicyRegistryInterface $retryPolicyRegistry = null,
        ?WorkerControlRuntime $workerControlRuntime = null,
    ): self {
        $flows ??= new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'default'),
        );

        $storage = new PostgresQueueStorage($pdo, $tableName);
        $provider = new PostgresQueueProvider($storage);
        $consumer = new PostgresMessageConsumer($storage);
        $workerControlRuntime ??= self::defaultPostgresWorkerControlRuntime($pdo);
        $envelopeSerializer ??= self::defaultEnvelopeSerializer($registry);
        $bus = new MessageBus(
            registry: $registry,
            flows: $flows,
            container: $container,
            queueProvider: $provider,
            envelopeSerializer: $envelopeSerializer,
            retryPolicyRegistry: $retryPolicyRegistry,
        );
        $worker = new MessageBusQueueWorker($bus, $envelopeSerializer);
        $runner = new QueueWorkerRunner($consumer, $worker, queueControl: $storage);

        return new self($bus, $runner, $provider, $consumer, $worker, $storage, $storage, $workerControlRuntime);
    }

    public static function compileRuntimeRegistry(
        ClassProviderInterface $provider,
        ?FlowRegistry $flows = null,
        string $libraryVersion = '5.0.0',
        string $sourceHash = '',
    ): CompiledMessageRegistry {
        $definition = (new MessageRegistryCompiler())->compile($provider, $flows, $libraryVersion, $sourceHash);

        return new CompiledMessageRegistry($definition);
    }

    private static function defaultEnvelopeSerializer(MessageRegistryInterface $registry): EnvelopeSerializerInterface
    {
        if (!$registry instanceof MessageNameResolverInterface) {
            throw new RuntimeException('Default envelope serializer requires registry implementing MessageNameResolverInterface.');
        }

        return new DefaultEnvelopeSerializer(new JsonMessageSerializer($registry));
    }

    private static function defaultPostgresWorkerControlRuntime(PDO $pdo): WorkerControlRuntime
    {
        $storage = new PostgresWorkerControlStorage($pdo);
        $service = new DefaultWorkerControlService($storage, $storage);

        return new WorkerControlRuntime(
            $service,
            $storage,
            $storage,
            $storage,
            $storage,
            $storage,
        );
    }

    private static function required(
        ContainerInterface $container,
        string $fqcn,
        string $alias,
        string $expectedType,
        string $role,
    ): object {
        $service = self::optional($container, $fqcn, $alias, $expectedType, $role);

        if ($service === null) {
            throw new ContainerServiceNotFound([$fqcn, $alias], $role, $expectedType);
        }

        return $service;
    }

    private static function optional(
        ContainerInterface $container,
        string $fqcn,
        string $alias,
        string $expectedType,
        string $role,
    ): ?object {
        foreach ([$fqcn, $alias] as $id) {
            try {
                if (!$container->has($id)) {
                    continue;
                }

                $service = $container->get($id);
            } catch (NotFoundExceptionInterface $e) {
                throw new ContainerServiceNotFound([$id], $role, $expectedType, previous: $e);
            } catch (ContainerExceptionInterface $e) {
                throw new ContainerServiceInvalid([$id], $role, $expectedType, 'container error', previous: $e);
            }

            if (!$service instanceof $expectedType) {
                throw new ContainerServiceInvalid([$id], $role, $expectedType, self::actualType($service));
            }

            return $service;
        }

        return null;
    }

    private static function actualType(mixed $value): string
    {
        return \is_object($value) ? $value::class : \get_debug_type($value);
    }
}
