# Framework integration

## Generic PSR-11

```php
$container->set(MessageBusInterface::class, fn (ContainerInterface $c) => new MessageBus(
    registry: $c->get(CompiledMessageRegistry::class),
    flows: $c->get(FlowRegistry::class),
    container: $c,
));
```

Полный пример: [Generic PSR-11](docs/examples/frameworks/generic-psr11.md).

## Symfony

```php
$services->set(MessageBusInterface::class, MessageBus::class)
    ->arg('$registry', service(CompiledMessageRegistry::class))
    ->arg('$flows', service(FlowRegistry::class))
    ->arg('$container', service('service_container'));
```

Полный пример: [Symfony DI](docs/examples/frameworks/symfony-di.md).

## Laravel

```php
$this->app->singleton(MessageBusInterface::class, fn ($app) => new MessageBus(
    registry: $app->make(CompiledMessageRegistry::class),
    flows: $app->make(FlowRegistry::class),
    container: $app,
));
```

Полный пример: [Laravel](docs/examples/frameworks/laravel.md).

## Spiral

```php
$container->bindSingleton(MessageBusInterface::class, fn (Container $container) => new MessageBus(
    registry: $container->get(CompiledMessageRegistry::class),
    flows: $container->get(FlowRegistry::class),
    container: $container,
));
```

Полный пример: [Spiral](docs/examples/frameworks/spiral.md).

## Yii3

```php
MessageBusInterface::class => static fn (ContainerInterface $container) => new MessageBus(
    registry: $container->get(CompiledMessageRegistry::class),
    flows: $container->get(FlowRegistry::class),
    container: $container,
),
```

Полный пример: [Yii3](docs/examples/frameworks/yii3.md).
