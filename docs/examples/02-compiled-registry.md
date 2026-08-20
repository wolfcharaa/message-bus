# Compiled registry for production

Registry можно собрать в build step и загрузить как PHP artifact.

```php
use DI\ContainerBuilder;
use Wolfcharaa\MessageBus\Discovery\ChainClassProvider;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;
use Wolfcharaa\MessageBus\Dumper\SymfonyVarExporterRegistryDumper;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$provider = new ChainClassProvider(
    new ComposerClassMapProvider(
        classMapFile: __DIR__ . '/../vendor/composer/autoload_classmap.php',
        namespacePrefixes: ['App\\'],
    ),
);

$definition = (new MessageRegistryCompiler())->compile(
    $provider,
    libraryVersion: '5.0.0',
    sourceHash: 'build-hash',
);

$php = (new SymfonyVarExporterRegistryDumper())->dump($definition);
file_put_contents(__DIR__ . '/../var/cache/message_bus_registry.php', $php);
```

Runtime:

```php
$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/../var/cache/message_bus_registry.php');

$bus = new MessageBus(
    registry: $registry,
    flows: $registry->definition()->flows,
    container: $container,
);
```
