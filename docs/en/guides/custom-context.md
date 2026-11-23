---
doc_id: guides.custom-context
contract_revision: 1
---

# Custom MessageContext

Create an interface extending `MessageContext` and register an exact `MessageContextFactory` for that type.

- The factory receives only `MessageContextSeed`: dispatcher, immutable metadata, and cancellation signal.

- The compiler binds the context type to the handler method; a missing factory blocks composition.

- Do not put PDO, a container, or non-portable application objects into persisted metadata.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
