---
doc_id: guides.handler-middleware
contract_revision: 2
---

# Handler middleware

`HandlerMiddleware<TResult>` wraps one handler attempt. The complete configured
order is:

```text
global middleware → delivery-policy middleware → #[Handler] middleware → handler
```

Global middleware is passed as ready process-local objects:

```php
$middleware = new HandlerMiddlewares(global: [
    new TraceMiddleware($tracer),
]);
```

Put scenario-specific middleware next to the handler. The class is compiled
into `HandlerBinding`, so each Event subscription can have its own chain:

```php
final class SendWelcomeHandler
{
    #[Handler(
        bindingId: 'command.send-welcome',
        middleware: [ValidateRecipient::class, AuditWelcome::class],
    )]
    public function __invoke(SendWelcome $command): void
    {
    }
}
```

Register every referenced class in the same `HandlerServiceResolver`/PSR-11
scope as handlers. The container creates the middleware when that binding is
invoked. A `get()` exception escapes unchanged; `MBR030` rejects a class that
does not implement `HandlerMiddleware` while compiling the registry.

A durable policy can add reusable attempt semantics:

```php
$policy = new DeliveryPolicy(
    name: 'transactional',
    maxAttempts: 3,
    middleware: [TransactionMiddleware::class],
);
```

The ordered policy class references are persisted in the delivery policy
snapshot. Every retry therefore uses the accepted chain and requires those
services to remain resolvable for the delivery lifetime. Policy middleware is
only used by durable worker attempts; Query, Request, and inline Event use the
global and handler-local levels.

Middleware may invoke `NextHandler` zero times to short-circuit or exactly once
to continue. A second call fails immediately. Return values and exceptions
pass through unchanged unless a middleware deliberately transforms them;
`finally` cleanup remains the middleware's responsibility. Before/after unwind
order is the reverse of entry order.

The pipeline surrounds handler execution, not durable acceptance. Code that
must run before a delivery is written belongs at the application's dispatch or
transaction boundary. The built-in atomic idempotency boundary remains the
outer durable effect boundary: on an already completed replay it may skip the
configured handler pipeline entirely.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
