# Laravel

```php
use Illuminate\Support\ServiceProvider;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

final class MessageBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FlowRegistry::class, fn () => new FlowRegistry(
            FlowDefinition::sync('default'),
            FlowDefinition::async('async')->transport('postgres', 'default'),
        ));

        $this->app->singleton(CompiledMessageRegistry::class, fn () => CompiledMessageRegistry::fromFile(
            base_path('bootstrap/cache/message_bus_registry.php'),
        ));

        $this->app->singleton(MessageBusInterface::class, fn ($app) => new MessageBus(
            registry: $app->make(CompiledMessageRegistry::class),
            flows: $app->make(FlowRegistry::class),
            container: $app,
        ));
    }
}
```

Queue services:

```php
$this->app->singleton(PostgresQueueStorage::class, fn () => new PostgresQueueStorage($pdo));
$this->app->alias(PostgresQueueProvider::class, 'message_bus.queue_provider');
$this->app->alias(PostgresMessageConsumer::class, 'message_bus.consumer');
$this->app->alias(MessageBusQueueWorker::class, 'message_bus.worker');
$this->app->alias(QueueWorkerRunner::class, 'message_bus.runner');
```

Handlers and middleware must be resolvable from Laravel container by FQCN.
