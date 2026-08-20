# Symfony DI

```php
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Execution\QueueExecutionStrategy;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(DefaultMessageContextFactory::class);
    $services->set(SequentialExecutionStrategy::class);
    $services->set(QueueExecutionStrategy::class);

    $services->set(FlowRegistry::class)
        ->factory([MessageBusSymfonyFactory::class, 'flows']);

    $services->set(CompiledMessageRegistry::class)
        ->factory([CompiledMessageRegistry::class, 'fromFile'])
        ->arg('$file', '%kernel.cache_dir%/message_bus_registry.php');

    $services->set(MessageBusInterface::class, MessageBus::class)
        ->arg('$registry', service(CompiledMessageRegistry::class))
        ->arg('$flows', service(FlowRegistry::class))
        ->arg('$container', service('service_container'));
};
```

```php
final class MessageBusSymfonyFactory
{
    public static function flows(): FlowRegistry
    {
        return new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'default'),
        );
    }
}
```

Queue services:

```php
$services->set(PostgresQueueStorage::class)->arg('$pdo', service(PDO::class));
$services->alias('message_bus.queue_provider', PostgresQueueProvider::class);
$services->alias('message_bus.consumer', PostgresMessageConsumer::class);
$services->alias('message_bus.worker', MessageBusQueueWorker::class);
$services->alias('message_bus.runner', QueueWorkerRunner::class);
```

Handlers and middleware must be registered as services by their FQCN.
