# Yii3

```php
use Psr\Container\ContainerInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

return [
    FlowRegistry::class => static fn () => new FlowRegistry(
        FlowDefinition::sync('default'),
        FlowDefinition::async('async')->transport('postgres', 'default'),
    ),

    CompiledMessageRegistry::class => static fn () => CompiledMessageRegistry::fromFile(
        dirname(__DIR__) . '/runtime/cache/message_bus_registry.php',
    ),

    MessageBusInterface::class => static fn (ContainerInterface $container) => new MessageBus(
        registry: $container->get(CompiledMessageRegistry::class),
        flows: $container->get(FlowRegistry::class),
        container: $container,
    ),
];
```

Queue services:

```php
return [
    PostgresQueueStorage::class => static fn () => new PostgresQueueStorage($pdo),
    'message_bus.queue_provider' => PostgresQueueProvider::class,
    'message_bus.consumer' => PostgresMessageConsumer::class,
    'message_bus.worker' => MessageBusQueueWorker::class,
    'message_bus.runner' => QueueWorkerRunner::class,
];
```
