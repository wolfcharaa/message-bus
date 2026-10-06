# PostgreSQL runtime and CLI

Bootstrap file may return `MessageBusRuntime`, `QueueWorkerRunner` or a PSR-11 container with `MessageBusInterface` registered.

```php
<?php

use DI\ContainerBuilder;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Postgres\CallbackPdoConnectionProvider;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

$container = (new ContainerBuilder())
    ->useAutowiring(true)
    ->build();

$registry = CompiledMessageRegistry::fromFile(__DIR__ . '/../var/cache/message_bus_registry.php');
$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::async('async')->transport('postgres', 'default'),
);

return MessageBusRuntime::postgres(
    pdo: new CallbackPdoConnectionProvider(
        static fn (): PDO => new PDO(
            $_ENV['DATABASE_DSN'],
            $_ENV['DATABASE_USER'],
            $_ENV['DATABASE_PASSWORD'],
        ),
    ),
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

In auto mode both parent and child reset their process-local copies of the inherited PostgreSQL provider immediately after `fork()`. The parent reconnects before its next storage call; the child resolves bootstrap again and opens its own connection before handling the job. A raw `PDO`/`StaticPdoConnectionProvider` is therefore rejected in auto mode; use `CallbackPdoConnectionProvider` or another reconnect-capable provider.
