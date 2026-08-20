# Generic PSR-11

Core требует `Psr\Container\ContainerInterface`. Handlers, middleware, context factories и execution strategies должны быть доступны из container.

```php
use Psr\Container\ContainerInterface;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

$container->set(FlowRegistry::class, fn () => new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::async('async')->transport('postgres', 'default'),
));

$container->set(CompiledMessageRegistry::class, fn () => CompiledMessageRegistry::fromFile(
    __DIR__ . '/var/cache/message_bus_registry.php',
));

$container->set(MessageBusInterface::class, fn (ContainerInterface $container) => new MessageBus(
    registry: $container->get(CompiledMessageRegistry::class),
    flows: $container->get(FlowRegistry::class),
    container: $container,
));
```

Queue aliases are optional but convenient:

```php
$container->set('message_bus.queue_provider', fn ($c) => new PostgresQueueProvider($c->get(PostgresQueueStorage::class)));
$container->set('message_bus.consumer', fn ($c) => new PostgresMessageConsumer($c->get(PostgresQueueStorage::class)));
$container->set('message_bus.worker', fn ($c) => new MessageBusQueueWorker($c->get(MessageBusInterface::class), $envelopeSerializer));
$container->set('message_bus.runner', fn ($c) => new QueueWorkerRunner($c->get('message_bus.consumer'), $c->get('message_bus.worker')));
```

`vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php` can load a PSR-11 container directly when it contains `MessageBusInterface`.
