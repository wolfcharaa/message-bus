# Domain handlers

`DomainHandler` is intended for small synchronous domain capabilities: load a domain value, apply one local operation, or persist a result without starting another MessageBus scenario.

Unlike application handlers, a domain handler receives only its message. It cannot receive `MessageContextInterface`, so nested `dispatch()` and `publish()` stay in the application pipeline.

```php
use Wolfcharaa\MessageBus\Attribute\DomainHandler;

final readonly class FindAddress
{
    public function __construct(public string $id)
    {
    }
}

#[DomainHandler(message: FindAddress::class)]
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

The default flow is `domain_capability`. When the compiler is used without an explicit `FlowRegistry`, it adds this flow as synchronous automatically.

If the application supplies its own `FlowRegistry`, register `domain_capability` explicitly:

```php
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;

$flows = new FlowRegistry(
    FlowDefinition::sync('default'),
    FlowDefinition::sync('domain_capability'),
);
```

A different synchronous flow can be selected directly on the attribute:

```php
#[DomainHandler(message: FindAddress::class, flow: 'domain_read')]
final class FindAddressHandler
{
    // ...
}
```

Async flows are rejected with `registry.domain_handler.async_flow`. A domain handler with a second argument is rejected with `registry.handler.invalid_signature`.

## Boundary

Use `DomainHandler` when one message maps to one small domain operation. Keep business ordering, cross-capability calls, audit required by a use case, and event publication in a regular context-aware pipeline handler.

The library validates this universal execution contract only. It does not require a particular namespace or project directory layout.
