# Spiral

```php
use Spiral\Boot\Bootloader\Bootloader;
use Spiral\Core\Container;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

final class MessageBusBootloader extends Bootloader
{
    public function init(Container $container): void
    {
        $container->bindSingleton(FlowRegistry::class, fn () => new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'default'),
        ));

        $container->bindSingleton(CompiledMessageRegistry::class, fn () => CompiledMessageRegistry::fromFile(
            directory('runtime') . 'cache/message_bus_registry.php',
        ));

        $container->bindSingleton(MessageBusInterface::class, fn (Container $container) => new MessageBus(
            registry: $container->get(CompiledMessageRegistry::class),
            flows: $container->get(FlowRegistry::class),
            container: $container,
        ));
    }
}
```

Queue services:

```php
$container->bindSingleton(PostgresQueueStorage::class, fn () => new PostgresQueueStorage($pdo));
$container->bindSingleton('message_bus.queue_provider', PostgresQueueProvider::class);
$container->bindSingleton('message_bus.consumer', PostgresMessageConsumer::class);
$container->bindSingleton('message_bus.worker', MessageBusQueueWorker::class);
$container->bindSingleton('message_bus.runner', QueueWorkerRunner::class);
```
