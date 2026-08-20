# PostgreSQL runtime and CLI

Bootstrap file may return `MessageBusRuntime`, `QueueWorkerRunner` or a PSR-11 container with `MessageBusInterface` registered.

```php
<?php

use DI\ContainerBuilder;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

$pdo = new PDO($_ENV['DATABASE_DSN'], $_ENV['DATABASE_USER'], $_ENV['DATABASE_PASSWORD']);
$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/../var/cache/message_bus_registry.php');
$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::async('async')->transport('postgres', 'default'),
);

return MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
);
```

Create schema:

```bash
vendor/bin/message-bus queue:schema:postgres --table=message_bus__queue_jobs
```

Run one worker loop:

```bash
vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php --stop-when-empty
```

Run auto mode with `pcntl` master process and child workers:

```bash
vendor/bin/message-bus worker:run \
  --bootstrap=config/message_bus_runtime.php \
  --mode=auto \
  --workers=4
```

In auto mode each child process resolves bootstrap again, so database/container resources are fresh in the child process.
