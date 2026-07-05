# MessageBus

Lightweight PHP message bus with handler registry, middleware pipeline, event subscribers, message envelopes, headers, and optional queue dispatching.

The library is intended for applications that keep business actions behind small command, query, and event messages, while still needing per-message middleware and lazy handler construction through a PSR container.

This project follows an idea inspired by [vudaltsov](https://github.com/vudaltsov), who laid the conceptual foundation for this style of message bus design.

## Requirements

- PHP `^7.4 | 8.*`
- `psr/container`
- `psr/clock`
- `psr/log`
- `ext-json`

## Installation

```bash
composer require romanfedorskij/message-bus
```

## Messages

Messages are plain PHP objects. A message may implement `Message`, `Command`, `Query`, or `Event`, but ordinary objects are also supported.

```php
use Wolfcharaa\MessageBus\Message\Command;

final class CreateUserMessage implements Command
{
    public string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }
}
```

## Handlers

Handlers can be invokable classes or callable methods. The callable receives the message and the current context.

```php
use Wolfcharaa\MessageBus\Message\Context;

final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, Context $context): int
    {
        return 123;
    }
}
```

## Registry

Register message definitions with their handlers. `LazyHandlerRegistry` builds handlers only when a message is dispatched.

```php
use Wolfcharaa\MessageBus\Builder\Builder;
use Wolfcharaa\MessageBus\HandlerRegistry\LazyHandlerRegistry;
use Wolfcharaa\MessageBus\HandlerRegistry\MessageDefinition;

$registry = new LazyHandlerRegistry(
    new Builder($container),
    [],
    (new MessageDefinition(CreateUserMessage::class))
        ->setHandlerFactory([CreateUserAction::class])
);
```

## Dispatch

```php
use Wolfcharaa\MessageBus\MessageBus;

$bus = new MessageBus($registry, null, null);

$userId = $bus->dispatch(new CreateUserMessage('user@example.com'));
```

Nested dispatches are available through `Context`; causation and correlation ids are propagated automatically.

```php
final class CreateUserAction
{
    public function __invoke(CreateUserMessage $message, Context $context): int
    {
        $context->dispatch(new UserCreatedEvent($message->email));

        return 123;
    }
}
```

## Events

Events may have multiple subscribers. Middleware is applied once around the whole event dispatch, not once per subscriber.

```php
use Wolfcharaa\MessageBus\Message\Event;

final class UserCreatedEvent implements Event
{
    public string $email;

    public function __construct(string $email)
    {
        $this->email = $email;
    }
}

$definition = (new MessageDefinition(UserCreatedEvent::class))
    ->setIsEvent(true)
    ->setHandlerFactory([SendWelcomeEmailAction::class])
    ->setHandlerFactory([WriteAuditLogAction::class]);
```

## Headers

Headers carry metadata alongside the message envelope.

```php
use Wolfcharaa\MessageBus\Header;
use Wolfcharaa\MessageBus\PublishOptions;

final class RequestHeader implements JsonSerializable
{
    public string $requestId;

    public function __construct(string $requestId)
    {
        $this->requestId = $requestId;
    }

    public function jsonSerialize(): array
    {
        return get_object_vars($this);
    }
}

$bus->dispatch(
    new CreateUserMessage('user@example.com'),
    new PublishOptions(null, new Header(new RequestHeader('request-1')))
);
```

Default headers can be attached to a message definition:

```php
(new MessageDefinition(CreateUserMessage::class))
    ->setDefaultHeader(new Header(new RequestHeader('default-request')))
    ->setHandlerFactory([CreateUserAction::class]);
```

Runtime headers override default headers of the same class.

## Queue Dispatch

Queue support is intentionally transport-agnostic. The library provides:

- `QueueProviderInterface` for application-specific enqueue logic.
- `QueueMiddleware` for intercepting queued messages.
- `QueueHeader` for marking whether the message is being enqueued or already executed by a worker.

Register the middleware globally or for selected messages:

```php
use Wolfcharaa\MessageBus\Middleware\QueueMiddleware;

$registry = new LazyHandlerRegistry(
    new Builder($container),
    [QueueMiddleware::class],
    (new MessageDefinition(CreateUserMessage::class))
        ->setShouldQueue()
        ->setHandlerFactory([CreateUserAction::class])
);
```

Implement a provider in the application:

```php
use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\Queue\QueueProviderInterface;

final class DatabaseQueueProvider implements QueueProviderInterface
{
    public function enqueue(Envelope $envelope): void
    {
        $payload = json_encode($envelope, JSON_THROW_ON_ERROR);

        // Save $payload to the application queue storage.
    }
}
```

When `setShouldQueue()` is enabled, `MessageBus` adds `QueueHeader(false)` to the envelope. `QueueMiddleware` sees the header, sends the envelope to `QueueProviderInterface`, and stops synchronous execution.

## Worker Execution

The worker should restore the message and dispatch it with `QueueHeader::started()`. This tells `QueueMiddleware` that the job is already running in a worker and must continue to the real handler.

```php
use Wolfcharaa\MessageBus\Envelope;
use Wolfcharaa\MessageBus\Header;
use Wolfcharaa\MessageBus\Queue\QueueHeader;

$data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
$envelope = Envelope::restore(
    $data,
    new Header(QueueHeader::started())
);

$bus->dispatchEnvelope($envelope);
```

If the application has custom headers, reconstruct them in the `Header` object passed to `Envelope::restore()`.

For queued events, only one queue job is created. In the worker, all event subscribers are executed.

## Middleware

Middleware receives the current `Context` and `Pipeline`.

```php
use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Middleware\Middleware;
use Wolfcharaa\MessageBus\Pipeline\Pipeline;

final class TransactionMiddleware implements Middleware
{
    public function handle(Context $context, Pipeline $pipeline)
    {
        // begin transaction

        try {
            $result = $pipeline->continue();
            // commit transaction

            return $result;
        } catch (Throwable $e) {
            // rollback transaction
            throw $e;
        }
    }
}
```

## Testing

```bash
composer test
```

The test suite covers:

- Header replacement and merging.
- Queue middleware enqueue/continue behavior.
- Queued event behavior for both lazy and array registries.
