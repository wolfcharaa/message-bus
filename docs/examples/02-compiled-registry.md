# Пример 2. Compiled registry для production

В production не стоит выполнять reflection discovery на каждый запрос.
Правильный путь: собрать registry при build/cache warmup и загрузить готовый PHP artifact.

## Сборка artifact

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\Discovery\ChainClassProvider;
use Wolfcharaa\MessageBus\Discovery\ComposerClassMapProvider;
use Wolfcharaa\MessageBus\Discovery\Psr4DirectoryClassProvider;
use Wolfcharaa\MessageBus\Dumper\SymfonyVarExporterRegistryDumper;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;

$provider = new ChainClassProvider(
    new ComposerClassMapProvider(
        classMapFile: __DIR__ . '/../vendor/composer/autoload_classmap.php',
        namespacePrefixes: ['App\\'],
    ),
    new Psr4DirectoryClassProvider([
        'App\\' => __DIR__ . '/../src',
    ]),
);

$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
);

$definition = (new MessageRegistryCompiler())->compile(
    $provider,
    $flows,
    libraryVersion: '4.0.0',
);

$php = (new SymfonyVarExporterRegistryDumper())->dump($definition);
\file_put_contents(__DIR__ . '/../var/cache/message_bus_registry.php', $php);
```

## Загрузка runtime

```php
<?php

declare(strict_types=1);

use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;

$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/../var/cache/message_bus_registry.php');
$bus = new MessageBus($registry, $registry->definition()->flows);
```

Artifact содержит `schemaVersion = 4`.
Если приложение попытается загрузить несовместимый registry, loader упадёт сразу.

