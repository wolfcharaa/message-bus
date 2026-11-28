---
doc_id: concepts.messages-and-handlers
contract_revision: 4
---

# Messages and handlers

`Message<TResult>` has four exact roles: Query and Request return immediately, Command returns an acceptance receipt, and Event returns an aggregate publication receipt.

- Put `#[Handler]` on a public method; its first parameter is the concrete message and its optional second parameter is a concrete `MessageContext`.

- Query/Request/Command require exactly one handler; Event allows zero or more stable subscription IDs.

- `#[Handler(bindingId: 'command.name')]` gives a Command a stable route and
  persisted target ID independent of its PHP class. Omitting it keeps the class
  name for backward compatibility. Event identity remains
  `#[Handler(subscriptionId: '...')]`; Query and Request accept neither durable
  identity. `MBR029` identifies an identity placed on the wrong role.

- Command and durable Event require `#[MessageName]` and a versioned `#[Payload]`.

- `#[Handler(middleware: [...])]` declares the middleware classes used only by
  this binding. The compiler stores their order in the registry artifact;
  `MBR030` identifies the exact invalid entry. See
  [handler middleware](../guides/handler-middleware.md) for global and policy
  composition.

- The runtime never infers a role from a class name or handler attribute.

- Handler resolver/container failures are never discarded. Query and Request
  expose them directly; aggregate Event failure keeps every original exception
  in `PublicationFailed::$failures` and chains the first one as `previous`. A
  durable worker settles the current attempt before rethrowing a handler
  resolution failure; the PCNTL child renders it before terminating.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
