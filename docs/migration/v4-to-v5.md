# Migration v4 -> v5

`v5` intentionally changes publish, queue, worker runtime and DI contracts.

## Main breaking changes

- `MessageBusInterface::publish()` returns `PublishResult` instead of `void`.
- `MessageContextInterface::publish()` returns `PublishResult`.
- `publishMany()` is added for batch publish.
- Async publish without matching async bindings returns an empty `PublishResult`, not an error.
- `MessageBus` requires `Psr\Container\ContainerInterface`.
- `ServiceResolverInterface`, `InstantiatingServiceResolver` and `PsrContainerServiceResolver` are removed.
- `QueueMessage` includes retry policy key and resolved retry policy snapshot.
- `SerializedEnvelope` includes explicit `schemaVersion`.
- `MessageConsumerInterface` includes `cancel()`.
- `symfony/console` and `psr/simple-cache` are package dependencies.
- `psr/log` is a package dependency because core `LoggingMiddleware` uses PSR-3.
- `MESSAGE_BUS_FORCE_SYNC=1` replaces project-level `QUEUE_SYNC` as the library debug override.

## DI migration

Before:

```php
use Wolfcharaa\MessageBus\Invoker\PsrContainerServiceResolver;

$resolver = new PsrContainerServiceResolver($container);

$bus = new MessageBus(
    $registry,
    $flows,
    queueProvider: $queueProvider,
    resolver: $resolver,
);
```

After:

```php
use Psr\Container\ContainerInterface;
use Wolfcharaa\MessageBus\MessageBus;

assert($container instanceof ContainerInterface);

$bus = new MessageBus(
    registry: $registry,
    flows: $flows,
    container: $container,
    queueProvider: $queueProvider,
);
```

Before/after table:

| Before | After |
| --- | --- |
| `ServiceResolverInterface` | Removed. Use `Psr\Container\ContainerInterface`. |
| `InstantiatingServiceResolver` | Removed. MessageBus does not ship a zero-config container. |
| `PsrContainerServiceResolver` | Removed. Pass PSR-11 container directly. |
| `ReflectionCallableInvoker($resolver)` | `ReflectionCallableInvoker($container)` |
| `new MessageBus($registry, $flows)` | `new MessageBus(registry: $registry, flows: $flows, container: $container)` |

Container lookup order for infrastructure fallback:

| Role | FQCN/interface id | Alias |
| --- | --- | --- |
| Queue provider | `QueueProviderInterface::class` | `message_bus.queue_provider` |
| Envelope serializer | `EnvelopeSerializerInterface::class` | `message_bus.envelope_serializer` |
| Invoker | `CallableInvokerInterface::class` | `message_bus.invoker` |
| Message id generator | `MessageIdGenerator::class` | `message_bus.message_id_generator` |
| Clock | `ClockInterface::class` | `message_bus.clock` |
| Retry policy registry | `RetryPolicyRegistryInterface::class` | `message_bus.retry_policy_registry` |

Handlers, middleware, context factories and execution strategies must be resolvable from the same PSR-11 container by FQCN.

## Publish migration

Before:

```php
$bus->publish(new UserCreatedEvent($userId));
```

After:

```php
$result = $bus->publish(new UserCreatedEvent($userId));

foreach ($result->executions() as $execution) {
    // queued execution exposes queueMessageId for polling
}
```

If enqueue fails, `PublishFailed` is thrown and contains partial `PublishResult`:

```php
try {
    $result = $bus->publishMany($messages);
} catch (PublishFailed $e) {
    $partial = $e->result();
}
```

## Retry migration

Retry policy is no longer only a runtime key. Queue jobs store:

- `retryPolicyKey`;
- resolved `RetryPolicySnapshot`;
- `maxAttempts`.

Default policy:

- key: `default`;
- max attempts: `3`;
- exponential backoff: `30s`, multiplier `2`, max `300s`.

## Queue worker migration

Adapters implementing `MessageConsumerInterface` must add:

```php
public function cancel(ReceivedQueueMessage $message, Throwable $reason): void;
```

Framework workers should prefer `QueueWorkerRunner` over hand-written loops.

## PostgreSQL runtime

The built-in PostgreSQL transport uses:

- transport: `postgres`;
- default queue: `default`;
- default table: `message_bus__queue_jobs`.

Runtime helper now requires container:

```php
$runtime = MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
);
```

Generate schema:

```bash
vendor/bin/message-bus queue:schema:postgres --table=message_bus__queue_jobs
```

Run worker:

```bash
vendor/bin/message-bus worker:run --bootstrap=config/message_bus_runtime.php
```

## Payload serializer changes

`SerializedMessage` now has an optional fifth constructor argument: `payloadEncoding`. Existing code that passes four arguments continues to use `SerializedMessage::PAYLOAD_ENCODING_PLAIN`.

PostgreSQL queue storage now delegates envelope conversion to `SerializedEnvelopeNormalizer`. New stored envelopes are written in camelCase and include `message.payloadEncoding`. The default normalizer can still read legacy snake_case envelope keys.

Use `JsonMessageSerializer` for portable cross-language messages. Use `PhpSerializeMessageSerializer` when a PHP-only application needs to preserve PHP objects that cannot be represented as JSON constructor payloads.

## Cache result migration

`MessageCacheMiddleware` still uses JSON result serialization by default. PHP-only projects can use `PhpSerializeResultSerializer` to cache richer PHP result objects.

The middleware now accepts `CacheResultPolicyInterface`. Existing constructor calls are compatible because the default policy caches all results, matching the previous behavior.

Cache keys use a sha256 fingerprint based on the message class, optional identity key, serialized message and vary headers. This removes the previous JSON-only limitation from cache key generation.

## Registry compile migration

Use `CompiledRegistryFileWriter` instead of manual `file_put_contents()` when dumping compiled registry files. It writes to a temporary file in the target directory and then renames it atomically.

Use `RegistryRuntimeLoader` when the application should prefer a compiled registry file but still be able to compile from a class provider in dev/test.

## Logging middleware

`LoggingMiddleware` is available in core and requires a PSR-3 logger. Default mode logs failures only. More verbose lifecycle logging is opt-in through `LoggingMiddlewareMode`.
