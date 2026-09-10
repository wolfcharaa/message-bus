# Contextless handlers

Contextless handlers are regular `QueryHandler`, `CommandHandler`, or `EventSubscriber` bindings with `contextAware: false`.

Use them when a small operation should receive only its message and constructor dependencies. The handler cannot receive `MessageContextInterface`, so nested `dispatch()` and `publish()` remain explicit application orchestration.

```php
use Wolfcharaa\MessageBus\Attribute\QueryHandler;

final readonly class FindAddress
{
    public function __construct(public string $id)
    {
    }
}

#[QueryHandler(message: FindAddress::class, contextAware: false)]
final class FindAddressHandler
{
    public function __construct(private AddressStorage $storage)
    {
    }

    public function __invoke(FindAddress $message): AddressResult
    {
        return AddressResult::fromAddress($this->storage->find($message->id));
    }
}
```

Dependencies still come from the PSR-11 container through the handler constructor. The restriction applies to execution context, not dependency injection.

## Flow rules

Contextless handlers use the same flow rules as their handler kind:

- query handlers must be sync and must return a non-void result;
- command handlers may be sync or async, but must return `void`;
- event subscribers should return `void` or `null`.

A different synchronous flow can be selected directly on the attribute:

```php
#[QueryHandler(message: FindAddress::class, flow: 'domain_read', contextAware: false)]
final class FindAddressHandler
{
    // ...
}
```

A contextless handler with a second argument is rejected with `registry.handler.invalid_signature`.

## Boundary

Use `contextAware: false` when one message maps to one small operation. Keep business ordering, cross-capability calls, audit required by a use case, and event publication in a regular context-aware pipeline handler.

The library validates this universal execution contract only. It does not require a particular namespace or project directory layout.
